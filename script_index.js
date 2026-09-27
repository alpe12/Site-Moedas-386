// ==========================================
// CONFIGURAÇÕES E VARIÁVEIS GLOBAIS DO INDEX
// ==========================================
const API_URL_RESUMO = 'api/ranking.php';
const API_URL_CONTEUDO = 'api/conteudo_publica.php';
const INTERVALO_CARROSSEL_MS = 5000;

let slideIndexIndex = 1;
let intervaloCarrossel = null;
let eventosCarregados = []; // [{id, tag, titulo, descricao, rodape, link}, ...]

window.addEventListener('DOMContentLoaded', async () => {
    try {
        const resposta = await fetch(API_URL_CONTEUDO, { cache: "default" });
        if (!resposta.ok) throw new Error("Erro ao carregar conteúdo da home.");
        const conteudo = await resposta.json();

        construirCarrossel(conteudo.carrossel || []);
        construirAbasEventos(conteudo.eventos || []);
        construirProjetos(conteudo.projetos || []);

        mostrarSlides(slideIndexIndex);
        iniciarAutoAvancoCarrossel();
        if (eventosCarregados.length) mostrarEvento(eventosCarregados[0].id);
    } catch (erro) {
        console.error("Erro ao carregar conteúdo da home:", erro);
    }

    try {
        const resposta = await fetch(API_URL_RESUMO, { cache: "default" });
        if (!resposta.ok) throw new Error("Erro de resposta do servidor.");

        const resumo = await resposta.json();
        construirPodioGrafico("podio-lideres-gerais", resumo.lideres || []);
        construirPodioGrafico("podio-mestres-moedas", resumo.mestres || []);
        construirResumoTurmas(resumo.turmas || []);
    } catch (erro) {
        console.error("Erro ao carregar o ranking na home:", erro);
        const erroFeedback = `<div class="texto-estado texto-estado--erro">Erro ao atualizar ranking</div>`;
        document.getElementById("podio-lideres-gerais").innerHTML = erroFeedback;
        document.getElementById("podio-mestres-moedas").innerHTML = erroFeedback;
        const resumoTurmas = document.getElementById("resumo-turmas-home");
        if (resumoTurmas) resumoTurmas.innerHTML = erroFeedback;
    }
});

// Roda em paralelo com o resto (listener separado) pra não atrasar o
// carrossel/eventos/ranking esperando essa checagem de login.
window.addEventListener('DOMContentLoaded', personalizarBoasVindas);

/**
 * Personaliza o cartão de boas-vindas quando o aluno está logado: troca o
 * título pelo nome dele e remove o botão "Acessar Meu Perfil" (não faz
 * sentido mandar pra si mesmo, já está na home). Deslogado (401), o
 * cartão fica como está — texto genérico com o botão de acesso.
 */
async function personalizarBoasVindas() {
    try {
        const resposta = await fetch('api/profile.php', { credentials: 'same-origin', cache: 'no-store' });
        if (!resposta.ok) return;

        const aluno = await resposta.json();
        if (!aluno || !aluno.nome) return;

        const titulo = document.getElementById('titulo-boas-vindas');
        if (titulo) titulo.textContent = `Olá, ${aluno.nome}!`;

        document.getElementById('botao-boas-vindas')?.remove();
    } catch (erro) {
        console.error("Erro ao verificar login na home:", erro);
    }
}

/** Monta os slides do carrossel a partir do conteúdo carregado do servidor. */
function construirCarrossel(carrossel) {
    const conteiner = document.querySelector('.conteiner-carrossel');
    if (!conteiner || !carrossel.length) return;

    const slides = carrossel.map(slide => `
        <div class="meus-slides efeito-suave">
            <img src="${escapeHtml(slide.imagem)}" alt="${escapeHtml(slide.legenda || '')}">
            <div class="legenda-slide">${escapeHtml(slide.legenda || '')}</div>
        </div>`).join('');

    conteiner.innerHTML = slides + `
        <a class="anterior" data-slide="-1">&#10094;</a>
        <a class="proximo" data-slide="1">&#10095;</a>`;

    // Clique manual numa seta: navega e reinicia a contagem do auto-avanço.
    conteiner.querySelectorAll('.anterior, .proximo').forEach(seta => {
        seta.addEventListener('click', () => mudarSlide(Number(seta.dataset.slide), true));
    });

    // Pausa o auto-avanço com o mouse sobre o carrossel; ao sair, retoma
    // com a contagem zerada.
    conteiner.addEventListener('mouseenter', pararAutoAvancoCarrossel);
    conteiner.addEventListener('mouseleave', iniciarAutoAvancoCarrossel);
}

/** Inicia o auto-avanço do carrossel. Se já estiver rodando, reinicia a
 *  contagem — por isso serve tanto pro início quanto pra "resetar o tempo". */
function iniciarAutoAvancoCarrossel() {
    pararAutoAvancoCarrossel();
    if (document.getElementsByClassName("meus-slides").length > 1) {
        intervaloCarrossel = setInterval(() => mudarSlide(1), INTERVALO_CARROSSEL_MS);
    }
}

function pararAutoAvancoCarrossel() {
    clearInterval(intervaloCarrossel);
    intervaloCarrossel = null;
}

/** @param manual `true` quando a troca vem de um clique do usuário nas setas: reinicia a contagem do auto-avanço. */
function mudarSlide(n, manual = false) {
    mostrarSlides(slideIndexIndex += n);
    if (manual) iniciarAutoAvancoCarrossel();
}

function mostrarSlides(n) {
    const slides = document.getElementsByClassName("meus-slides");
    if (slides.length === 0) return;
    if (n > slides.length) { slideIndexIndex = 1; }
    if (n < 1) { slideIndexIndex = slides.length; }
    for (const slide of slides) slide.classList.remove('ativo');
    slides[slideIndexIndex - 1].classList.add('ativo');
}

/** Monta as abas numeradas e os botões "Inscrever-se" a partir dos eventos carregados. */
function construirAbasEventos(eventos) {
    eventosCarregados = eventos;

    const cabecalho = document.querySelector('.cabecalho-abas');
    const rodapeAcao = document.querySelector('.rodape-acao');
    if (!cabecalho || !rodapeAcao) return;

    cabecalho.innerHTML = eventos.map((evento, i) =>
        `<button class="botao-numero" data-evento="${escapeHtml(evento.id)}">${String(i + 1).padStart(2, '0')}</button>`
    ).join('');
    cabecalho.querySelectorAll('.botao-numero').forEach(botao => {
        botao.addEventListener('click', () => mostrarEvento(botao.dataset.evento));
    });

    rodapeAcao.innerHTML = eventos.map(evento =>
        `<a id="link-botao-${escapeHtml(evento.id)}" href="${escapeHtml(evento.link || '#')}" target="_blank" class="botao-evento"><button class="botao-acao">Inscrever-se</button></a>`
    ).join('');
}

/** Monta o acordeão de projetos a partir do conteúdo carregado do servidor.
 *  Os itens usam <input type="radio"> com o mesmo "name": abrir um fecha
 *  os outros nativamente (comportamento padrão de grupo de rádio), sem
 *  depender de JS pra isso. */
function construirProjetos(projetos) {
    const acordeon = document.querySelector('.acordeon');
    if (!acordeon) return;

    acordeon.innerHTML = projetos.map((projeto, i) => `
        <div class="acordeon-item">
            <input type="radio" name="acordeon-projetos" id="projeto${i + 1}">
            <label for="projeto${i + 1}">${escapeHtml(projeto.titulo)}</label>
            <div class="conteudo">
                ${projeto.parceria ? `<h4>${escapeHtml(projeto.parceria)}</h4>` : ''}
                <p>${escapeHtml(projeto.descricao)}</p>
            </div>
        </div>`).join('');
}

// Melhoria progressiva do acordeão: rádios nativos não se desmarcam com um
// novo clique, então isso permite fechar o item já aberto clicando nele de
// novo. Sem JS o acordeão continua funcionando normalmente — só não fecha
// sozinho, que já é o comportamento nativo de um grupo de rádio.
document.addEventListener('click', (evento) => {
    const label = evento.target.closest('.acordeon-item label');
    if (!label) return;
    const input = document.getElementById(label.getAttribute('for'));
    if (!input || input.type !== 'radio') return;
    evento.preventDefault();
    input.checked = !input.checked;
});

function mostrarEvento(idEvento) {
    const evento = eventosCarregados.find(e => e.id === idEvento);
    if (!evento) return;

    const tagElemento = document.getElementById("tag-evento");
    tagElemento.innerText = evento.tag;
    document.getElementById("evento-titulo").innerText = evento.titulo;
    document.getElementById("evento-descricao").innerText = evento.descricao;
    document.getElementById("evento-rodape").innerText = evento.rodape;

    tagElemento.classList.remove('tag-esporte', 'tag-oficina');
    if (evento.tag === "Esporte") tagElemento.classList.add('tag-esporte');
    else if (evento.tag === "Oficina") tagElemento.classList.add('tag-oficina');

    eventosCarregados.forEach(e => {
        const botaoContainer = document.getElementById(`link-botao-${e.id}`);
        if (botaoContainer) botaoContainer.classList.toggle('ativo', e.id === idEvento);
    });

    document.querySelectorAll(".botao-numero").forEach(btn => {
        btn.classList.toggle("ativo", btn.dataset.evento === idEvento);
    });
}

function construirPodioGrafico(idConteiner, topAlunos) {
    const conteiner = document.getElementById(idConteiner);
    if (!conteiner) return;

    if (!topAlunos.length) {
        conteiner.innerHTML = `<div class="texto-estado">Ainda não há dados suficientes.</div>`;
        return;
    }

    const estilosMedalha = ["podio-ouro", "podio-prata", "podio-bronze"];
    conteiner.innerHTML = topAlunos.map((aluno, index) => {
        const identificacao = `${aluno.nome} (${formatarTurma(aluno.turma)})`;
        return `
            <div class="linha-linha-podio ${estilosMedalha[index] || ''}">
                <span class="emblema-medalha">${index + 1}º</span>
                <span class="nome-usuario" title="${escapeHtml(identificacao)}">${escapeHtml(identificacao)}</span>
                <strong>${formatarMoeda(aluno.valor)} 🪙</strong>
            </div>`;
    }).join('');
}

function construirResumoTurmas(topTurmas) {
    const conteiner = document.getElementById("resumo-turmas-home");
    if (!conteiner) return;

    if (!topTurmas.length) {
        conteiner.innerHTML = `<div class="texto-estado">Ainda não há dados suficientes.</div>`;
        return;
    }

    const maiorTotal = Math.max(...topTurmas.map(t => Number(t.total) || 0), 1);
    const estilosBarra = ["barra-ouro", "barra-prata", "barra-bronze"];

    conteiner.innerHTML = topTurmas.map((turma, index) => {
        const largura = Math.max(4, Math.round((Number(turma.total) || 0) / maiorTotal * 100));
        return `
            <div class="linha-grafico">
                <div class="info-grafico"><span>${escapeHtml(formatarTurma(turma.turma))}</span><strong>${formatarMoeda(turma.total)} 🪙</strong></div>
                <div class="fundo-barra-grafico"><div class="barra-grafico ${estilosBarra[index] || 'barra-bronze'}" style="width: ${largura}%"></div></div>
            </div>`;
    }).join('');
}
