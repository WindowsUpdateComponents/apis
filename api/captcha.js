// api/captcha.js
const sessions = new Map();

module.exports = async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') return res.status(204).end();

    const { action, state } = req.query;

    // 1. O Bot regista a sessão de Captcha na Vercel
    if (action === 'create' && req.method === 'POST') {
        const { state, ticket, sitekey, rqdata, rqtoken, userId } = req.body;
        if (!state || !sitekey) return res.status(400).json({ ok: false, error: 'Dados insuficientes' });

        sessions.set(state, {
            ticket,
            sitekey,
            rqdata,
            rqtoken,
            userId,
            status: 'pending',
            captcha_token: null,
            createdAt: Date.now()
        });

        return res.status(200).json({ ok: true });
    }

    // 2. Renderiza a página para o utilizador resolver o hCaptcha
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

        const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Discord — Verificação</title>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; background: #313338; color: #f2f3f5; font-family: sans-serif; display: flex; align-items: center; justify-content: center; }
  .card { background: #2b2d31; padding: 40px; border-radius: 12px; text-align: center; max-width: 450px; width: 90%; }
  h1 { font-size: 20px; margin-bottom: 8px; }
  p { color: #b5bac1; font-size: 14px; margin-bottom: 20px; }
  #captcha-box { display: flex; justify-content: center; margin: 20px 0; min-height: 78px; }
  #status { font-size: 13px; color: #949ba4; }
  .ok { color: #23a55a !important; }
  .err { color: #f23f43 !important; }
</style>
</head>
<body>
  <div class="card">
    <h1>Verificação de Segurança</h1>
    <p>Resolva o captcha abaixo para concluir a verificação no Discord.</p>
    <div id="captcha-box"></div>
    <div id="status">Aguardando resolução...</div>
  </div>
<script>
  const STATE = ${JSON.stringify(state)};
  function onCaptchaSuccess(token) {
    document.getElementById('status').textContent = 'Validando resolução...';
    fetch('/api/captcha?action=submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ state: STATE, captcha_token: token })
    }).then(r => r.json()).then(data => {
      if (data.ok) {
        document.getElementById('status').textContent = '✅ Sucesso! Pode fechar esta página.';
        document.getElementById('status').className = 'ok';
      } else {
        document.getElementById('status').textContent = 'Erro ao processar verificação.';
        document.getElementById('status').className = 'err';
      }
    });
  }
  window.onCaptchaSuccess = onCaptchaSuccess;
  window.addEventListener('load', () => {
    let t = setInterval(() => {
      if (window.hcaptcha) {
        clearInterval(t);
        window.hcaptcha.render('captcha-box', ${JSON.stringify(hcaptchaOptions)});
      }
    }, 200);
  });
</script>
</body>
</html>`;
        return res.status(200).send(html);
    }

    // 3. Recebe o token do hCaptcha vindo do browser do utilizador
    if (action === 'submit' && req.method === 'POST') {
        const { state, captcha_token } = req.body;
        if (!sessions.has(state)) return res.status(404).json({ ok: false, error: 'Sessão expirada' });

        const session = sessions.get(state);
        session.captcha_token = captcha_token;
        session.status = 'resolved';
        sessions.set(state, session);

        return res.status(200).json({ ok: true });
    }

    // 4. O Bot faz Polling para consultar se o captcha já foi resolvido
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
                sessionId: session.sessionId
            });
        }
        return res.status(200).json({ ok: true, status: 'pending' });
    }

    return res.status(404).json({ error: 'Ação inválida' });
};
