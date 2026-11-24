document.addEventListener('DOMContentLoaded', () => {
    const config = window.M3_CONFIG;
    const metadataForm = document.getElementById('metadata-form');
    const stripeForm = document.getElementById('stripe-form');
    const btnInit = document.getElementById('btn-init-payment');
    const btnConfirm = document.getElementById('btn-confirm-payment');
    const statusBox = document.getElementById('status-message');

    let ecdhSession = null;
    let stripe = null;
    let elements = null;

    function base64ToArrayBuffer(base64) {
        const binary = window.atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    function arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    function setStepState(stepId, state) {
        const el = document.getElementById(stepId);
        if (!el) return;
        el.classList.remove('pending', 'done', 'error');
        el.classList.add(state);
    }

    function showMessage(text, type = 'info') {
        statusBox.textContent = text;
        statusBox.className = `alert ${type}`;
    }

    function formatCurrency(amountMinor, currency) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: currency || 'MXN',
        }).format(amountMinor / 100);
    }

    async function initEcdhSession() {
        try {
            const response = await fetch(`${config.apiBase}/sesion`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': 'Bearer mock_jwt_token_buyer',
                },
                body: JSON.stringify({
                    reservationId: config.reservationId,
                }),
            });

            const data = await response.json();
            if (!response.ok || data.status !== 'ok') {
                throw new Error(data.message || 'No se pudo iniciar la sesión ECDH.');
            }

            ecdhSession = data.session;

            const summary = data.reservationSummary;
            document.getElementById('event-name').textContent = summary.eventName;
            document.getElementById('event-venue').textContent = summary.venue;
            document.getElementById('seat-id').textContent = summary.seatId;
            document.getElementById('lock-status').textContent = 'Asiento bloqueado';
            document.getElementById('total-amount').textContent = formatCurrency(
                summary.amountMinor,
                summary.currency
            );

            setStepState('step-ecdh', 'done');
        } catch (err) {
            setStepState('step-ecdh', 'error');
            showMessage(`Error en handshake ECDH: ${err.message}`, 'error');
            btnInit.disabled = true;
        }
    }

    async function encryptMetadata(metadataObj) {
        const clientKeyPair = await window.crypto.subtle.generateKey(
            { name: 'ECDH', namedCurve: 'P-256' },
            true,
            ['deriveBits']
        );

        const serverPublicKey = await window.crypto.subtle.importKey(
            'spki',
            base64ToArrayBuffer(ecdhSession.serverPublicKey),
            { name: 'ECDH', namedCurve: 'P-256' },
            false,
            []
        );

        const sharedSecretBits = await window.crypto.subtle.deriveBits(
            { name: 'ECDH', public: serverPublicKey },
            clientKeyPair.privateKey,
            256
        );

        const hkdfKey = await window.crypto.subtle.importKey(
            'raw',
            sharedSecretBits,
            'HKDF',
            false,
            ['deriveKey']
        );

        const aesKey = await window.crypto.subtle.deriveKey(
            {
                name: 'HKDF',
                hash: 'SHA-256',
                salt: base64ToArrayBuffer(ecdhSession.salt),
                info: new TextEncoder().encode(ecdhSession.hkdfInfo),
            },
            hkdfKey,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt']
        );

        const iv = window.crypto.getRandomValues(new Uint8Array(12));
        const encodedPayload = new TextEncoder().encode(JSON.stringify(metadataObj));

        const ciphertextBuffer = await window.crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            aesKey,
            encodedPayload
        );

        const clientPublicKeySpki = await window.crypto.subtle.exportKey(
            'spki',
            clientKeyPair.publicKey
        );

        return {
            clientPublicKey: arrayBufferToBase64(clientPublicKeySpki),
            iv: arrayBufferToBase64(iv.buffer),
            ciphertext: arrayBufferToBase64(ciphertextBuffer),
        };
    }

    metadataForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!ecdhSession) return;

        btnInit.disabled = true;
        btnInit.textContent = 'Cifrando con AES-256-GCM...';
        showMessage('Derivando llave efímera y cifrando metadatos de la orden...', 'info');

        try {
            const buyerEmail = document.getElementById('buyer-email').value.trim();
            const encrypted = await encryptMetadata({
                buyerEmail,
                reservationId: config.reservationId,
                clientTimestamp: new Date().toISOString(),
            });

            setStepState('step-hkdf', 'done');

            const response = await fetch(`${config.apiBase}/intentos`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': 'Bearer mock_jwt_token_buyer',
                },
                body: JSON.stringify({
                    sessionId: ecdhSession.sessionId,
                    clientPublicKey: encrypted.clientPublicKey,
                    iv: encrypted.iv,
                    ciphertext: encrypted.ciphertext,
                }),
            });

            const data = await response.json();
            if (!response.ok || data.status !== 'ok') {
                throw new Error(data.message || 'Error al crear el intento de pago.');
            }

            if (!config.stripeKey || config.stripeKey.includes('aqui_tu_llave')) {
                throw new Error('Configura STRIPE_KEY (pk_test_...) en tu archivo .env.');
            }

            stripe = Stripe(config.stripeKey);
            elements = stripe.elements({
                clientSecret: data.clientSecret,
                appearance: {
                    theme: 'night',
                    variables: {
                        colorPrimary: '#3b82f6',
                        colorBackground: '#1e293b',
                        colorText: '#f8fafc',
                        borderRadius: '10px',
                    },
                },
            });

            const paymentElement = elements.create('payment');
            paymentElement.mount('#payment-element');

            metadataForm.classList.add('hidden');
            stripeForm.classList.remove('hidden');
            setStepState('step-stripe', 'done');
            showMessage(`Orden ${data.orderId} creada. Ingresa los datos de tu tarjeta en Stripe Sandbox.`, 'info');
        } catch (err) {
            setStepState('step-hkdf', 'error');
            showMessage(err.message, 'error');
            btnInit.disabled = false;
            btnInit.textContent = 'Cifrar orden y continuar al pago';
        }
    });

    stripeForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!stripe || !elements) return;

        btnConfirm.disabled = true;
        btnConfirm.textContent = 'Procesando con Stripe...';
        showMessage('Enviando pago directamente a Stripe Sandbox...', 'info');

        const { error, paymentIntent } = await stripe.confirmPayment({
            elements,
            redirect: 'if_required',
        });

        if (error) {
            showMessage(`Pago rechazado: ${error.message}`, 'error');
            btnConfirm.disabled = false;
            btnConfirm.textContent = 'Pagar ahora';
        } else if (paymentIntent && paymentIntent.status === 'succeeded') {
            showMessage(
                `¡Pago aprobado en Stripe! (${paymentIntent.id}). El Webhook confirmará tu orden.`,
                'success'
            );
            btnConfirm.textContent = 'Pago completado';
        }
    });

    initEcdhSession();
});
