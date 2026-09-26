// ==========================================
// UTILITÁRIOS COMPARTILHADOS POR TODAS AS PÁGINAS
// ==========================================

/**
 * Configuração pública (regras de senha, matrícula, turma, link do
 * WhatsApp, etc.) vem sempre do servidor — api/config.php é a única fonte
 * da verdade, então o navegador nunca guarda esses valores "fixos" no
 * código, só busca uma vez por carregamento de página.
 */
window.configPromise = fetch('api/config_publica.php', { cache: 'default' })
    .then(resposta => resposta.ok ? resposta.json() : Promise.reject())
    .catch(() => ({
        senhaMinTamanho: 8, senhaMinLetras: 0, senhaMinNumeros: 0, senhaMinEspeciais: 0,
        matriculaTamanho: 15, matriculaAnoMin: 2000, matriculaAnoMax: 2099,
        turmaTamanho: 4, whatsappLink: '#',
        mostrarApenasPrimeiraLetra: false, lojaVisivelSemLogin: false,
        rankingLimitePadrao: 20, rankingLimiteMaximo: 100,
    }));

/**
 * Escapa texto antes de inserir em innerHTML. Nome e turma vêm do que o
 * próprio aluno digitou no cadastro, então nunca devem ser inseridos "crus"
 * em HTML — sem isso, um cadastro malicioso poderia injetar script em todo
 * mundo que visse o ranking (XSS armazenado).
 */
function escapeHtml(valor) {
    const div = document.createElement('div');
    div.textContent = valor === undefined || valor === null ? '' : String(valor);
    return div.innerHTML;
}

function formatarMoeda(valor) {
    const numero = Number(valor);
    return (Number.isFinite(numero) ? numero : 0).toFixed(2);
}

/** "0901" -> "T0901". Só usar em lugares que não têm um rótulo "Turma" ao lado. */
function formatarTurma(turma) {
    const valor = String(turma ?? '').trim();
    return valor ? `T${valor}` : '';
}

function carregarMenu() {
    const header = document.querySelector('header');
    if (!header) return;

    header.innerHTML = `
        <div class="nav-container">
            <a href="/" class="logo-text" style="text-decoration:none;">386 <span>EcoCoin</span></a>
            <button type="button" class="menu-toggle" aria-label="Abrir menu" aria-expanded="false">☰</button>
            <nav><ul>
                <li><a href="/">INÍCIO</a></li>
                <li><a href="loja.html">LOJA</a></li>
                <li><a href="ranking.html">RANKING</a></li>
                <li><a href="perfil.html">PERFIL</a></li>
            </ul></nav>
        </div>`;

    const paginaAtual = location.pathname.split('/').pop() || 'index.html';
    header.querySelectorAll('nav a').forEach(link => {
        const href = link.getAttribute('href');
        const ehPaginaInicial = href === '/' && (paginaAtual === '' || paginaAtual === 'index.html');
        if (href === paginaAtual || ehPaginaInicial) link.classList.add('link-ativo');
    });
}

window.addEventListener('load', carregarMenu);

// ==========================================
// MENU MOBILE (hamburguer) — delegado no document pra funcionar tanto com
// o header estático do HTML quanto com o que carregarMenu() reescreve.
// ==========================================
document.addEventListener('click', (evento) => {
    const botaoToggle = evento.target.closest('.menu-toggle');
    if (botaoToggle) {
        const header = botaoToggle.closest('header');
        if (!header) return;
        const aberto = header.classList.toggle('menu-aberto');
        botaoToggle.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        botaoToggle.textContent = aberto ? '✕' : '☰';
        return;
    }

    // Clicou num link do menu (fecha) ou fora do header (fecha)
    const headerAberto = document.querySelector('header.menu-aberto');
    if (!headerAberto) return;
    if (evento.target.closest('header nav a') || !headerAberto.contains(evento.target)) {
        headerAberto.classList.remove('menu-aberto');
        const botao = headerAberto.querySelector('.menu-toggle');
        if (botao) { botao.setAttribute('aria-expanded', 'false'); botao.textContent = '☰'; }
    }
});

// Se a tela crescer pra tamanho de desktop com o menu aberto, fecha.
window.addEventListener('resize', () => {
    if (window.innerWidth > 720) {
        document.querySelectorAll('header.menu-aberto').forEach(header => {
            header.classList.remove('menu-aberto');
            const botao = header.querySelector('.menu-toggle');
            if (botao) { botao.setAttribute('aria-expanded', 'false'); botao.textContent = '☰'; }
        });
    }
});

// ==========================================
// AVISOS EM TELA (substituem alert() para mensagens informativas)
// ==========================================
function garantirConteinerAvisos() {
    let conteiner = document.getElementById('conteiner-avisos');
    if (!conteiner) {
        conteiner = document.createElement('div');
        conteiner.id = 'conteiner-avisos';
        conteiner.style.cssText = `
            position: fixed; top: 16px; right: 16px; z-index: 9999;
            display: flex; flex-direction: column; gap: 10px; max-width: 340px;`;
        document.body.appendChild(conteiner);
    }
    return conteiner;
}

/**
 * Mostra um aviso temporário no canto da tela em vez de um alert() do
 * navegador. tipo: 'info' | 'sucesso' | 'erro'.
 */
function mostrarAviso(mensagem, tipo = 'info', duracaoMs = 4500) {
    const conteiner = garantirConteinerAvisos();
    const cores = {
        info: { fundo: '#eff6ff', borda: '#2563eb', texto: '#1e3a8a' },
        sucesso: { fundo: '#f0fdf4', borda: '#16a34a', texto: '#14532d' },
        erro: { fundo: '#fef2f2', borda: '#dc2626', texto: '#7f1d1d' },
    };
    const cor = cores[tipo] || cores.info;

    const aviso = document.createElement('div');
    aviso.style.cssText = `
        background: ${cor.fundo}; border-left: 4px solid ${cor.borda}; color: ${cor.texto};
        padding: 12px 16px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        font-size: 0.9rem; font-weight: 600; line-height: 1.4;
        opacity: 0; transform: translateX(20px); transition: opacity 0.25s ease, transform 0.25s ease;`;
    aviso.textContent = mensagem;
    conteiner.appendChild(aviso);

    requestAnimationFrame(() => { aviso.style.opacity = '1'; aviso.style.transform = 'translateX(0)'; });

    setTimeout(() => {
        aviso.style.opacity = '0';
        aviso.style.transform = 'translateX(20px)';
        setTimeout(() => aviso.remove(), 300);
    }, duracaoMs);
}

/**
 * Restringe um campo a apenas dígitos enquanto o usuário digita, avisando
 * (sem travar a tela) quando algo é descartado.
 */
function restringirSomenteNumeros(input, nomeCampo) {
    input.addEventListener('input', () => {
        const original = input.value;
        const limpo = original.replace(/[^0-9]/g, '');
        if (limpo !== original) {
            input.value = limpo;
            mostrarAviso(`${nomeCampo}: só números são permitidos.`, 'info', 2500);
        }
    });
}

/** Restringe um campo a letras, espaços e pontuação básica de nome. */
function restringirSomenteLetras(input, nomeCampo) {
    input.addEventListener('input', () => {
        const original = input.value;
        const limpo = original.replace(/[^\p{L}\s'\-.]/gu, '');
        if (limpo !== original) {
            input.value = limpo;
            mostrarAviso(`${nomeCampo}: só letras são permitidas.`, 'info', 2500);
        }
    });
}

/**
 * Adiciona um botão de "mostrar/ocultar" a um campo de senha e,
 * opcionalmente, uma listinha de regras que acende verde/vermelho ao
 * digitar (tamanho mínimo, letras/números/especiais mínimos — conforme
 * vierem de api/config_publica.php). Chame com { checklist: true, regras }
 * só no campo de senha NOVA (cadastro/redefinição); nos demais (login,
 * confirmar senha) chame sem checklist, só pelo botão de mostrar/ocultar.
 */
function melhorarCampoSenha(input, { checklist = false, regras = null } = {}) {
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'position:relative;';
    input.replaceWith(wrapper);
    wrapper.appendChild(input);
    input.style.paddingRight = '42px';
    if (!input.autocomplete || input.autocomplete === 'off') input.autocomplete = input.autocomplete || 'off';

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.textContent = '👁️';
    botao.setAttribute('aria-label', 'Mostrar senha');
    botao.style.cssText = 'position:absolute; right:4px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:1.15rem; padding:6px; line-height:1;';
    wrapper.appendChild(botao);
    botao.addEventListener('click', () => {
        const mostrando = input.type === 'text';
        input.type = mostrando ? 'password' : 'text';
        botao.textContent = mostrando ? '👁️' : '🙈';
        botao.setAttribute('aria-label', mostrando ? 'Mostrar senha' : 'Ocultar senha');
    });

    if (checklist && regras) {
        const lista = document.createElement('div');
        lista.style.cssText = 'margin-top:8px; font-size:0.8rem; display:flex; flex-direction:column; gap:3px;';
        wrapper.insertAdjacentElement('afterend', lista);

        const itens = [{ testar: v => v.length >= regras.senhaMinTamanho, texto: `Pelo menos ${regras.senhaMinTamanho} caracteres` }];
        if (regras.senhaMinLetras > 0) itens.push({ testar: v => (v.match(/\p{L}/gu) || []).length >= regras.senhaMinLetras, texto: `Pelo menos ${regras.senhaMinLetras} letra(s)` });
        if (regras.senhaMinNumeros > 0) itens.push({ testar: v => (v.match(/[0-9]/g) || []).length >= regras.senhaMinNumeros, texto: `Pelo menos ${regras.senhaMinNumeros} número(s)` });
        if (regras.senhaMinEspeciais > 0) itens.push({ testar: v => (v.match(/[^\p{L}0-9]/gu) || []).length >= regras.senhaMinEspeciais, texto: `Pelo menos ${regras.senhaMinEspeciais} caractere(s) especial(is) (!@#$%^&*)` });

        const render = () => {
            lista.innerHTML = itens.map(item => {
                const ok = item.testar(input.value);
                return `<span style="color:${ok ? '#4ade80' : '#dc2626'};">${ok ? '✓' : '✗'} ${escapeHtml(item.texto)}</span>`;
            }).join('');
        };
        input.addEventListener('input', render);
        render();
    }
}

// ==========================================
// EMOJI FALLBACK LOADER
// ==========================================
(function() {
    const s = document.createElement('script');
    s.src = 'emoji-fallback.js';
    document.head.appendChild(s);
})();
