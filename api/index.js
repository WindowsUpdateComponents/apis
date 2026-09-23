// api/captcha.js — versão corrigida
const sessions = new Map();

module.exports = async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') return res.status(204).end();

    const { action, state } = req.query;

    // 1. Bot cria sessão
    if (action === 'create' && req.method === 'POST') {
        const { state, ticket, sitekey, rqdata, rqtoken, userId, sessionId } = req.body;
        if (!state || !sitekey) return res.status(400).json({ ok: false, error: 'Dados insuficientes' });

        sessions.set(state, {
            ticket,
            sitekey,
            rqdata,
            rqtoken,
            sessionId,          // ✅ salvo agora
            userId,
            status: 'pending',
            captcha_token: null,
            createdAt: Date.now()
        });

        return res.status(200).json({ ok: true });
    }

    // 2. Página do captcha
    if (action === 'login' && req.method === 'GET') {
        if (!state || !sessions.has(state)) {
            return res.status(404).send('<h1>Sessão expirada ou inválida</h1>');
        }
        const session = sessions.get(state);

        const hcaptchaOptions = {
            sitekey: session.sitekey,
            theme: 'dark',
            callback: 'onCaptchaSuccess'
        };
        if (session.rqdata) {
            hcaptchaOptions.enterprisePayload = { rqdata: session.rqdata };
        }

        // ... (mesmo HTML de antes)
        // Importante: adicione este script para detectar mudança de rqdata:
        // (opcional, mas recomendado)
        const html = `<!-- mesmo HTML de antes -->`;
        return res.status(200).send(html);
    }

    // 3. Recebe token do browser
    if (action === 'submit' && req.method === 'POST') {
        const { state, captcha_token } = req.body;
        if (!sessions.has(state)) return res.status(404).json({ ok: false, error: 'Sessão expirada' });

        const session = sessions.get(state);
        session.captcha_token = captcha_token;
        session.status = 'resolved';
        sessions.set(state, session);
        return res.status(200).json({ ok: true });
    }

    // 4. Polling do bot
    if (action === 'session' && req.method === 'GET') {
        if (!state || !sessions.has(state)) return res.status(404).json({ ok: false });

        const session = sessions.get(state);
        if (session.status === 'resolved') {
            return res.status(200).json({
                ok: true,
                status: 'token_received',
                captcha_token: session.captcha_token,
                ticket: session.ticket,
                rqtoken: session.rqtoken,
                sessionId: session.sessionId   // ✅ agora funciona
            });
        }
        return res.status(200).json({ ok: true, status: 'pending' });
    }

    return res.status(404).json({ error: 'Ação inválida' });
};
