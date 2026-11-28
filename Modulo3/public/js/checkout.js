document.addEventListener('DOMContentLoaded', () => {
    const config = window.M3_CONFIG;
    const metadataForm = document.getElementById('metadata-form');
    const stripeForm = document.getElementById('stripe-form');
    const btnInit = document.getElementById('btn-init-payment');
    const btnConfirm = document.getElementById('btn-confirm-payment');
    const statusBox = document.getElementById('status-message');

    let ecdhSession = null;
    let activeReservationId = null;
    let countdownInterval = null;
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

    function isValidLuhn(cardNumber) {
        let sum = 0;
        let shouldDouble = false;
        for (let i = cardNumber.length - 1; i >= 0; i--) {
            let digit = parseInt(cardNumber.charAt(i), 10);
            if (shouldDouble) {
                digit *= 2;
                if (digit > 9) digit -= 9;
            }
            sum += digit;
            shouldDouble = !shouldDouble;
        }
        return sum % 10 === 0;
    }

    function startCountdown(remainingSeconds) {
        const timerEl = document.getElementById('countdown-timer');
        let secondsLeft = Number(remainingSeconds) || 0;

        if (countdownInterval) clearInterval(countdownInterval);

        const render = () => {
            const mins = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
            const secs = String(secondsLeft % 60).padStart(2, '0');
            timerEl.textContent = `${mins}:${secs}`;
        };

        render();

        countdownInterval = setInterval(async () => {
            secondsLeft--;
            if (secondsLeft <= 0) {
                clearInterval(countdownInterval);
                timerEl.textContent = '00:00 (Expirado)';
                document.getElementById('lock-status').textContent = 'Liberado por tiempo';
                btnInit.disabled = true;
                btnConfirm.disabled = true;
                showMessage('El tiempo de retencion termino. El asiento fue liberado automaticamente.', 'error');

                if (activeReservationId) {
                    await fetch(`${config.apiBase}/asientos/expirar`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ reservationId: activeReservationId }),
                    });
                }
                return;
            }
            render();
        }, 1000);
    }

    document.getElementById('btn-check-bin')?.addEventListener('click', async () => {
        const raw = document.getElementById('bin-input').value.replace(/\D/g, '');
        const resultEl = document.getElementById('bin-result');

        if (raw.length < 15 || raw.length > 16) {
            resultEl.textContent = 'Ingresa el numero completo de la tarjeta (15 o 16 digitos).';
            return;
        }

        if (!isValidLuhn(raw)) {
            resultEl.innerHTML = '❌ <strong>Numero de tarjeta invalido</strong> (no pasa la verificacion matematica Luhn).';
            return;
        }

        const bin6 = raw.substring(0, 6);
        const last4 = raw.substring(raw.length - 4);
        resultEl.textContent = `Luhn valido (**** ${last4}). Consultando banco emisor para BIN ${bin6}...`;

        try {
            const res = await fetch(`${config.apiBase}/bin/${encodeURIComponent(bin6)}`);
            const json = await res.json();
            if (!res.ok) throw new Error(json.message || 'BIN no encontrado');
            const d = json.data;
            resultEl.innerHTML =
                `✅ <strong>Tarjeta válida (**** ${last4})</strong><br>` +
                `🏦 Banco: <strong>${d.bank}</strong><br>` +
                `💳 Red / Tipo: <strong>${d.scheme} · ${d.type} (${d.tier || 'ESTÁNDAR'})</strong> · 🌍 País: <strong>${d.country}</strong>`;
        } catch (err) {
            resultEl.innerHTML = `⚠️ Luhn valido (**** ${last4}), pero: ${err.message}`;
        }
    });

    async function initEcdhSession() {
        try {
            const lockRes = await fetch(`${config.apiBase}/asientos/reservar`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': 'Bearer mock_jwt_token_buyer',
                },
                body: JSON.stringify({ seatId: config.seatId }),
            });

            const lockData = await lockRes.json();
            if (!lockRes.ok || lockData.status !== 'ok') {
                throw new Error(lockData.message || 'El asiento no esta disponible.');
            }

            activeReservationId = lockData.reservation.reservationId;
            document.getElementById('reservation-id').textContent = activeReservationId;
            startCountdown(lockData.reservation.remainingSeconds);

            const response = await fetch(`${config.apiBase}/sesion`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': 'Bearer mock_jwt_token_buyer',
                },
                body: JSON.stringify({ reservationId: activeReservationId }),
            });

            const data = await response.json();
            if (!response.ok || data.status !== 'ok') {
                throw new Error(data.message || 'No se pudo iniciar la sesion ECDH.');
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
            document.getElementById('lock-status').textContent = 'No disponible';
            showMessage(err.message, 'error');
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
        showMessage('Derivando llave efimera y cifrando metadatos de la orden...', 'info');

        try {
            const buyerName = document.getElementById('buyer-name').value.trim();
            const buyerPhone = document.getElementById('buyer-phone').value.trim();
            const buyerEmail = document.getElementById('buyer-email').value.trim();

            const encrypted = await encryptMetadata({
                buyerName,
                buyerPhone,
                buyerEmail,
                reservationId: activeReservationId,
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

            const paymentElement = elements.create('payment', {
                wallets: {
                    link: 'never',
                },
            });
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
            if (countdownInterval) clearInterval(countdownInterval);
            document.getElementById('lock-status').textContent = 'Pagado';
            showMessage(
                `Pago aprobado en Stripe (${paymentIntent.id}). El Webhook confirmara tu orden y disparara la emision del boleto al Modulo 4.`,
                'success'
            );
            btnConfirm.textContent = 'Pago completado';
        }
    });

    initEcdhSession();
});
