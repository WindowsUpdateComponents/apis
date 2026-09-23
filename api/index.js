const { parse } = require('url');

// NOTA: Em produção Vercel, utilize Vercel KV (Redis) para persistência entre Lambdas.
const sessions = global._sessions || new Map();
if (process.env.NODE_ENV !== 'production') global._sessions = sessions;

module.exports = async (req, res) => {
    const parsedUrl = parse(req.url, true);
    const { query, method } = req;
    const action = query.action;

    // CORS Headers
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (method === 'OPTIONS') {
        return res.status(200).end();
    }

    // Helper para ler body JSON
    let body = {};
    if (method === 'POST') {
        try {
            const buffers = [];
            for await (const chunk of req) buffers.push(chunk);
            const data = Buffer.concat(buffers).toString();
            body = data ? JSON.parse(data) : {};
        } catch {}
    }

    // ---------------------------------------------------------
    // ROTA: Criar Sessão
    // ---------------------------------------------------------
    if (action === 'create' && method === 'POST') {
        const { state, ticket, sitekey, sessionId, rqdata, rqtoken, userId } = body;
        if (!state) return res.status(400).json({ ok: false, error: 'State ausente' });

        sessions.set(state, {
            state,
            ticket: ticket || '',
            sitekey: sitekey || '',
            sessionId: sessionId || '',
            rqdata: rqdata || '',
            rqtoken: rqtoken || '',
            userId: userId || '',
            status: 'pending',
            captcha_token: null,
            result: null,
            error: null,
            created_at: Date.now()
        });

        return res.status(200).json({ ok: true });
    }

    // ---------------------------------------------------------
    // ROTA: Consultar Sessão (Polling feito pelo main.js)
    // ---------------------------------------------------------
    if (action === 'session') {
        const state = query.state;
        const sess = sessions.get(state);

        if (!sess) {
            return res.status(404).json({ ok: false, error: 'Sessão não encontrada' });
        }

        return res.status(200).json({
            ok: true,
            status: sess.status,
            captcha_token: sess.captcha_token,
            result: sess.result,
            error: sess.error
        });
    }

    // ---------------------------------------------------------
    // ROTA: Enviar Solução do Captcha
    // ---------------------------------------------------------
    if (action === 'submit' && method === 'POST') {
        const { state, token } = body;
        const sess = sessions.get(state);

        if (!sess) return res.status(404).json({ ok: false, error: 'Sessão expirada' });

        sess.captcha_token = token;
        sess.status = 'token_received';
        sessions.set(state, sess);

        return res.status(200).json({ ok: true });
    }

    // ---------------------------------------------------------
    // ROTA: Definir Resultado
    // ---------------------------------------------------------
    if (action === 'set_result' && method === 'POST') {
        const { state, result, error } = body;
        const sess = sessions.get(state);

        if (sess) {
            if (result) {
                sess.result = result;
                sess.status = 'success';
            } else if (error) {
                sess.error = error;
                sess.status = 'error';
            }
            sessions.set(state, sess);
        }

        return res.status(200).json({ ok: true });
    }

    // ---------------------------------------------------------
    // ROTA: Resetar Desafio
    // ---------------------------------------------------------
    if (action === 'reset' && method === 'POST') {
        const { state, rqdata, rqtoken, sessionId, sitekey } = body;
        const sess = sessions.get(state);

        if (sess) {
            sess.status = 'pending';
            sess.captcha_token = null;
            if (rqdata) sess.rqdata = rqdata;
            if (rqtoken) sess.rqtoken = rqtoken;
            if (sessionId) sess.sessionId = sessionId;
            if (sitekey) sess.sitekey = sitekey;
            sessions.set(state, sess);
        }

        return res.status(200).json({ ok: true });
    }

    // ---------------------------------------------------------
    // ROTA: Renderizar Página Web do Captcha
    // ---------------------------------------------------------
    if (action === 'login') {
        const state = query.state;
        const sess = sessions.get(state);

        if (!sess) {
            res.setHeader('Content-Type', 'text/html');
            return res.status(400).send('<h2 style="color: white; font-family: sans-serif; text-align: center; margin-top: 20%;">Sessão inválida ou expirada. Volte ao Discord e tente novamente.</h2>');
        }

        const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Segurança</title>
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <style>
        body {
            background-color: #0f172a;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .card {
            background: #1e293b;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }
        h2 { margin-bottom: 0.5rem; color: #38bdf8; }
        p { color: #94a3b8; font-size: 0.95rem; margin-bottom: 1.5rem; }
        .h-captcha { display: inline-block; }
        #status { margin-top: 1rem; font-weight: bold; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Verificação Humana</h2>
        <p>Complete o desafio abaixo para prosseguir com a autorização no Discord.</p>
        <div class="h-captcha" 
             data-sitekey="${sess.sitekey}" 
             data-callback="onSuccess"
             ${sess.rqdata ? `data-rqdata="${sess.rqdata}"` : ''}>
        </div>
        <div id="status"></div>
    </div>

    <script>
        function onSuccess(token) {
            document.getElementById('status').innerText = 'Validando no Discord...';
            document.getElementById('status').className = '';

            fetch('/api?action=submit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ state: '${state}', token: token })
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    document.getElementById('status').innerText = '✅ Verificado com sucesso! Pode fechar esta aba.';
                    document.getElementById('status').className = 'success';
                } else {
                    document.getElementById('status').innerText = '❌ Erro ao enviar resultado.';
                    document.getElementById('status').className = 'error';
                }
            })
            .catch(() => {
                document.getElementById('status').innerText = '❌ Erro de conexão.';
                document.getElementById('status').className = 'error';
            });
        }
    </script>
</body>
</html>`;

        res.setHeader('Content-Type', 'text/html');
        return res.status(200).send(html);
    }

    return res.status(400).json({ error: 'Ação inválida' });
};
