// ==========================================
// PÁGINA DE RANKING GERAL
// ==========================================
let debounceBuscaRanking = null;
let poolRankingCompleto = []; // usado para a tabela (quando sem busca no servidor) e para o resumo por turma — e também pra busca local, quando habilitada
let configRanking = null;
let buscaLocalHabilitada = false;

window.addEventListener('DOMContentLoaded', async () => {
    const corpo = document.getElementById('corpoTabelaRanking');
    if (!corpo) return;

    configRanking = await window.configPromise;
    // rankingBuscaLocal só faz sentido (e só é seguro) junto com
    // rankingBuscaLimitada — veja o comentário de RANKING_BUSCA_LOCAL em
    // config.php. Sem o limite ligado, a busca continua indo ao servidor.
    buscaLocalHabilitada = !!(configRanking.rankingBuscaLimitada && configRanking.rankingBuscaLocal);

    const seletorLimite = document.getElementById('limiteRanking');
    const campoBusca = document.getElementById('buscaNome');
    const grupoBusca = document.getElementById('grupoBusca');

    if (seletorLimite) construirOpcoesLimite(seletorLimite, configRanking);

    // A busca só existe quando o site mostra o primeiro nome — no modo "só
    // a primeira letra" ela é escondida e o servidor ignora qualquer
    // tentativa de busca mesmo que alguém chame a API direto.
    if (configRanking.mostrarApenasPrimeiraLetra) {
        if (grupoBusca) grupoBusca.style.display = 'none';
    } else if (campoBusca) {
        if (buscaLocalHabilitada) {
            // Filtrar uma lista já carregada é instantâneo — sem debounce.
            campoBusca.addEventListener('input', atualizarTabela);
        } else {
            campoBusca.addEventListener('input', () => {
                clearTimeout(debounceBuscaRanking);
                debounceBuscaRanking = setTimeout(atualizarTabela, 350);
            });
        }
    }

    if (seletorLimite) seletorLimite.addEventListener('change', atualizarTabela);

    await carregarPoolCompleto();
    atualizarTabela();
    renderizarResumoPorTurma();
});

/** Monta as opções do seletor "Mostrar": nunca passa do teto do servidor, e o teto sempre aparece como opção. */
function construirOpcoesLimite(seletor, config) {
    const passosPadrao = [10, 20, 50, 100, 200, 500];
    const maximo = Number(config.rankingLimiteMaximo) || 20;
    const padrao = Math.min(Number(config.rankingLimitePadrao) || 20, maximo);

    const valores = new Set(passosPadrao.filter(v => v < maximo));
    valores.add(maximo);
    valores.add(padrao);

    const ordenados = [...valores].filter(v => v > 0 && v <= maximo).sort((a, b) => a - b);

    seletor.innerHTML = ordenados.map(v => `<option value="${v}">${v}</option>`).join('');
    seletor.value = String(padrao);
}

/**
 * Busca uma única vez o topo geral (sem termo de busca). A tabela (quando
 * não há busca ativa, ou quando a busca é local) e o resumo por turma são
 * derivados deste mesmo pool no navegador — assim a página de ranking evita
 * requisições repetidas só pra re-filtrar algo que já teria vindo do
 * servidor de qualquer forma.
 *
 * Quando a busca local está habilitada, este pool precisa cobrir tudo que
 * uma busca no servidor enxergaria (max(rankingBuscaLimiteN,
 * rankingLimiteMaximo)) — senão filtrar localmente poderia deixar de
 * encontrar alguém que uma busca no servidor teria encontrado. Sem busca
 * local, basta o teto normal (rankingLimiteMaximo).
 */
async function carregarPoolCompleto() {
    const corpo = document.getElementById('corpoTabelaRanking');
    const limite = buscaLocalHabilitada
        ? Math.max(Number(configRanking.rankingBuscaLimiteN) || 0, Number(configRanking.rankingLimiteMaximo) || 0)
        : configRanking.rankingLimiteMaximo;

    try {
        const resposta = await fetch(`api/ranking.php?view=completo&limite=${limite}`, { cache: 'default' });
        if (!resposta.ok) throw new Error('Erro ao carregar o ranking.');
        const lista = await resposta.json();
        poolRankingCompleto = Array.isArray(lista) ? lista : [];
    } catch (erro) {
        console.error('Erro ao carregar ranking:', erro);
        poolRankingCompleto = [];
        if (corpo) corpo.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:40px; color:var(--cor-alerta); font-weight:700;">
            Não foi possível carregar o ranking agora. Tente novamente mais tarde.</td></tr>`;
    }
}

async function atualizarTabela() {
    const limite = Number(document.getElementById('limiteRanking')?.value || configRanking.rankingLimitePadrao || 20);
    const busca = configRanking.mostrarApenasPrimeiraLetra ? '' : (document.getElementById('buscaNome')?.value || '').trim();

    if (!busca) {
        renderizarTabelaCompleta(poolRankingCompleto.slice(0, limite));
        return;
    }

    if (buscaLocalHabilitada) {
        // O pool já carregado cobre exatamente o mesmo alcance que uma
        // busca no servidor teria — filtrar aqui dá o mesmo resultado, sem
        // gastar uma requisição por letra digitada.
        const buscaMin = busca.toLowerCase();
        const filtrados = poolRankingCompleto.filter(a => String(a.nome || '').toLowerCase().includes(buscaMin));
        renderizarTabelaCompleta(filtrados.slice(0, limite));
        return;
    }

    const params = new URLSearchParams({ view: 'completo', limite: String(limite), busca });
    try {
        const resposta = await fetch(`api/ranking.php?${params.toString()}`, { cache: 'default' });
        if (!resposta.ok) throw new Error('Erro ao buscar.');
        const lista = await resposta.json();
        renderizarTabelaCompleta(Array.isArray(lista) ? lista : []);
    } catch (erro) {
        console.error('Erro ao buscar no ranking:', erro);
        renderizarTabelaCompleta([]);
    }
}

function renderizarTabelaCompleta(lista) {
    const corpo = document.getElementById('corpoTabelaRanking');
    if (!corpo) return;

    if (lista.length === 0) {
        corpo.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:40px; color:#999;">
            Nenhum aluno encontrado.</td></tr>`;
        return;
    }

    corpo.innerHTML = lista.map((aluno, index) => `
        <tr>
            <td>${index + 1}º</td>
            <td>${escapeHtml(aluno.nome)}</td>
            <td>${escapeHtml(aluno.turma)}</td>
            <td class="valor-eco">${formatarMoeda(aluno.saldo)}</td>
        </tr>
    `).join('');
}

function renderizarResumoPorTurma() {
    const grade = document.getElementById('gradeTurmas');
    if (!grade) return;

    const totalPorTurma = new Map();
    for (const aluno of poolRankingCompleto) {
        // Turmas se repetem de número ano a ano — sem este filtro, um aluno
        // que não foi realocado desde o ano passado somaria sob o número
        // de uma turma que já é outra, este ano (mesma regra do servidor
        // em api/ranking.php).
        if (!aluno.turmaEsteAno) continue;
        const turma = String(aluno.turma || 'Sem turma').trim() || 'Sem turma';
        totalPorTurma.set(turma, (totalPorTurma.get(turma) || 0) + (Number(aluno.saldo) || 0));
    }

    const topTurmas = [...totalPorTurma.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5);

    if (topTurmas.length === 0) {
        grade.innerHTML = `<p style="color: var(--text-gray); text-align:center; width:100%;">Ainda não há dados suficientes.</p>`;
        return;
    }

    const estilosBorda = ['gold-border', 'silver-border', 'bronze-border', 'red-border', 'red-border'];
    grade.innerHTML = topTurmas.map(([turma, total], index) => `
        <div class="turma-card ${estilosBorda[index] || 'red-border'}">
            <span class="tag-azul" style="background: ${corPosicao(index)}; color: #000;">${index + 1}º Lugar</span>
            <h3>${escapeHtml(formatarTurma(turma))}</h3>
            <p class="valor-eco">🪙 ${formatarMoeda(total)}</p>
        </div>
    `).join('');
}

function corPosicao(index) {
    return ['#ffc107', '#c0c0c0', '#cd7f32', '#fa735b', '#fa735b'][index] || '#fa735b';
}
