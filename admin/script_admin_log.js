// Duas abas no mesmo lugar: ações dos administradores (admin_log.csv) e dos
// alunos (log_usuarios.csv). Ambas só leitura; a de alunos tem busca no servidor.
const ABAS_LOG = {
    admins: {
        subtitulo: 'Toda ação que muda algo no site fica registrada aqui, mais recente primeiro.',
        url: 'api/log.php?limite=300',
        colunas: ['Quando', 'Admin', 'Ação', 'Detalhes'],
        celulas: l => [formatarDataHora(l.data), l.admin_email, l.acao],
        vazio: 'Nenhuma ação registrada ainda.',
    },
    alunos: {
        subtitulo: 'O que os alunos fazem no site (entrar, sair, trocar dados, resgatar...), mais recente primeiro. Só administradores veem esta aba.',
        url: 'api/log_usuarios.php?limite=300',
        colunas: ['Quando', 'Matrícula', 'Aluno', 'Ação', 'Detalhes', 'IP'],
        celulas: l => [formatarDataHora(l.data), l.matricula, l.nome, l.acao],
        celulasDepois: l => [l.ip],
        vazio: 'Nenhuma ação de aluno registrada ainda.',
        comBusca: true,
    },
};

let abaAtual = 'admins';
let pedidoAtual = 0; // descarta respostas antigas se a aba/busca mudar no meio do caminho
let temporizadorBusca = null;

window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-aba]').forEach(botao => {
        botao.addEventListener('click', () => trocarAba(botao.dataset.aba));
    });
    document.getElementById('busca-log').addEventListener('input', () => {
        clearTimeout(temporizadorBusca);
        temporizadorBusca = setTimeout(carregarLog, 300);
    });
    // log.html?aba=alunos&busca=<texto> abre direto nessa aba, já filtrada
    // (é o link "Abrir no log de alunos" de aluno.html).
    const params = new URLSearchParams(location.search);
    const busca = params.get('busca');
    if (busca) document.getElementById('busca-log').value = busca;
    trocarAba(ABAS_LOG[params.get('aba')] ? params.get('aba') : 'admins');
});

function trocarAba(nome) {
    abaAtual = nome;
    const aba = ABAS_LOG[nome];
    document.querySelectorAll('[data-aba]').forEach(botao => {
        const ativa = botao.dataset.aba === nome;
        botao.classList.toggle('ativa', ativa);
        botao.setAttribute('aria-selected', String(ativa));
    });
    document.getElementById('subtitulo-log').textContent = aba.subtitulo;
    document.getElementById('barra-busca-log').classList.toggle('oculto', !aba.comBusca);
    document.getElementById('cabecalho-log').innerHTML =
        '<tr>' + aba.colunas.map(c => `<th>${escapeHtml(c)}</th>`).join('') + '</tr>';
    carregarLog();
}

async function carregarLog() {
    const aba = ABAS_LOG[abaAtual];
    const corpo = document.getElementById('corpo-log');
    const nColunas = aba.colunas.length;
    const meuPedido = ++pedidoAtual;

    let url = aba.url;
    if (aba.comBusca) {
        const busca = document.getElementById('busca-log').value.trim();
        if (busca) url += '&busca=' + encodeURIComponent(busca);
    }

    corpo.innerHTML = `<tr><td colspan="${nColunas}" class="admin-vazio">Carregando...</td></tr>`;
    try {
        const resposta = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        if (meuPedido !== pedidoAtual) return;
        if (tratarNaoAutenticado(resposta)) return;
        if (!resposta.ok) throw new Error('Erro ao carregar log.');
        const linhas = await resposta.json();
        if (meuPedido !== pedidoAtual) return;

        if (!Array.isArray(linhas) || linhas.length === 0) {
            corpo.innerHTML = `<tr><td colspan="${nColunas}" class="admin-vazio">${escapeHtml(aba.vazio)}</td></tr>`;
            return;
        }

        corpo.innerHTML = linhas.map((l, i) => {
            const detalhes = String(l.detalhes || '');
            const truncado = detalhes.length > 80;
            const resumo = truncado ? detalhes.slice(0, 80) + '…' : detalhes;
            const antes = aba.celulas(l).map((c, k) => `<td${k === 0 ? ' class="admin-nowrap"' : ''}>${escapeHtml(c)}</td>`).join('');
            const depois = (aba.celulasDepois ? aba.celulasDepois(l) : []).map(c => `<td class="admin-nowrap">${escapeHtml(c)}</td>`).join('');
            return `
            <tr>
                ${antes}
                <td>
                    <span data-resumo="${i}">${escapeHtml(resumo)}</span>
                    ${truncado ? `<button type="button" class="admin-btn secundario pequeno" data-expandir="${i}">Ver mais</button>
                    <div data-completo="${i}" class="admin-log-detalhe oculto">${escapeHtml(detalhes)}</div>` : ''}
                </td>
                ${depois}
            </tr>`;
        }).join('');

        corpo.querySelectorAll('[data-expandir]').forEach(btn => {
            btn.addEventListener('click', () => {
                const i = btn.dataset.expandir;
                const resumo = corpo.querySelector(`[data-resumo="${i}"]`);
                const completo = corpo.querySelector(`[data-completo="${i}"]`);
                const estaOculto = completo.classList.contains('oculto');
                completo.classList.toggle('oculto', !estaOculto);
                resumo.classList.toggle('oculto', estaOculto);
                btn.textContent = estaOculto ? 'Ver menos' : 'Ver mais';
            });
        });
    } catch (erro) {
        if (meuPedido !== pedidoAtual) return;
        console.error(erro);
        corpo.innerHTML = `<tr><td colspan="${nColunas}" class="admin-vazio">Não foi possível carregar o log.</td></tr>`;
    }
}
