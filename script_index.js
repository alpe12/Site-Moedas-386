// ==========================================
// CONFIGURAÇÕES E VARIÁVEIS GLOBAIS DO INDEX
// ==========================================
const API_URL_RESUMO = 'api/ranking.php';
const API_URL_CONTEUDO = 'api/conteudo_publica.php';

let slideIndexIndex = 1;
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
        setInterval(() => { mudarSlide(1); }, 5000);
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
        const erroFeedback = `<div style="color: var(--cor-alerta); text-align: center; padding: 10px; font-weight: 700;">Erro ao atualizar ranking</div>`;
        document.getElementById("podio-lideres-gerais").innerHTML = erroFeedback;
        document.getElementById("podio-mestres-moedas").innerHTML = erroFeedback;
        const resumoTurmas = document.getElementById("resumo-turmas-home");
        if (resumoTurmas) resumoTurmas.innerHTML = erroFeedback;
    }
});

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
        <a class="anterior" onclick="mudarSlide(-1)">&#10094;</a>
        <a class="proximo" onclick="mudarSlide(1)">&#10095;</a>`;
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
        `<a id="link-botao-${escapeHtml(evento.id)}" href="${escapeHtml(evento.link || '#')}" target="_blank" class="botao-evento" style="display:none"><button class="botao-acao">Inscrever-se</button></a>`
    ).join('');
}

/** Monta o acordeão de projetos a partir do conteúdo carregado do servidor. */
function construirProjetos(projetos) {
    const acordeon = document.querySelector('.acordeon');
    if (!acordeon) return;

    acordeon.innerHTML = projetos.map((projeto, i) => `
        <div class="acordeon-item">
            <input type="checkbox" id="projeto${i + 1}">
            <label for="projeto${i + 1}">${escapeHtml(projeto.titulo)}</label>
            <div class="conteudo">
                ${projeto.parceria ? `<h4>${escapeHtml(projeto.parceria)}</h4>` : ''}
                <p>${escapeHtml(projeto.descricao)}</p>
            </div>
        </div>`).join('');
}

function mostrarEvento(idEvento) {
    const evento = eventosCarregados.find(e => e.id === idEvento);
    if (!evento) return;

    const tagElemento = document.getElementById("tag-evento");
    tagElemento.innerText = evento.tag;
    document.getElementById("evento-titulo").innerText = evento.titulo;
    document.getElementById("evento-descricao").innerText = evento.descricao;
    document.getElementById("evento-rodape").innerText = evento.rodape;

    if (evento.tag === "Esporte") {
        tagElemento.style.backgroundColor = "#0e4768";
    } else if (evento.tag === "Oficina") {
        tagElemento.style.backgroundColor = "#fd7e14";
    } else {
        tagElemento.style.backgroundColor = "var(--primary-blue)";
    }

    eventosCarregados.forEach(e => {
        const botaoContainer = document.getElementById(`link-botao-${e.id}`);
        if (botaoContainer) botaoContainer.style.display = (e.id === idEvento) ? "inline-block" : "none";
    });

    document.querySelectorAll(".botao-numero").forEach(btn => {
        btn.classList.toggle("ativo", btn.dataset.evento === idEvento);
    });
}

function mudarSlide(n) { mostrarSlides(slideIndexIndex += n); }

function mostrarSlides(n) {
    let slides = document.getElementsByClassName("meus-slides");
    if (slides.length === 0) return;
    if (n > slides.length) { slideIndexIndex = 1 }
    if (n < 1) { slideIndexIndex = slides.length }
    for (let i = 0; i < slides.length; i++) { slides[i].style.display = "none"; }
    slides[slideIndexIndex - 1].style.display = "block";
}

function construirPodioGrafico(idConteiner, topAlunos) {
    const conteiner = document.getElementById(idConteiner);
    if (!conteiner) return;

    if (!topAlunos.length) {
        conteiner.innerHTML = `<div style="color: var(--texto-suave); padding: 10px; text-align: center;">Ainda não há dados suficientes.</div>`;
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
        conteiner.innerHTML = `<div style="color: var(--texto-suave); padding: 10px; text-align: center;">Ainda não há dados suficientes.</div>`;
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
