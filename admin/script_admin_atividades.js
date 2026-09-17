let atividadesCache = [];
let alunosSelecionados = []; // [{matricula, nome, turma}, ...]
let debounceBuscaAluno = null;
let ultimosResultadosBusca = [];

window.addEventListener('DOMContentLoaded', () => {
    document.getElementById('atividade-data').value = new Date().toISOString().slice(0, 10);

    document.getElementById('busca-aluno').addEventListener('input', (e) => {
        clearTimeout(debounceBuscaAluno);
        const termo = e.target.value.trim();
        if (!termo) { document.getElementById('resultados-busca-aluno').style.display = 'none'; return; }
        debounceBuscaAluno = setTimeout(() => buscarAlunos(termo), 300);
    });

    // Fecha a lista de resultados só quando clicar fora da busca — assim
    // dá pra buscar uma turma inteira e ir clicando em vários alunos sem
    // a lista sumir a cada clique.
    document.addEventListener('click', (e) => {
        const conteiner = document.getElementById('resultados-busca-aluno');
        const campoBusca = document.getElementById('busca-aluno');
        if (!conteiner.contains(e.target) && e.target !== campoBusca) {
            conteiner.style.display = 'none';
        }
    });

    document.getElementById('form-atividade').addEventListener('submit', salvarAtividade);
    document.getElementById('btn-cancelar-edicao').addEventListener('click', limparFormulario);

    carregarAtividades();
});

async function buscarAlunos(termo) {
    const conteiner = document.getElementById('resultados-busca-aluno');
    try {
        const resposta = await fetch(`api/alunos.php?busca=${encodeURIComponent(termo)}`, { credentials: 'same-origin' });
        if (tratarNaoAutenticado(resposta)) return;
        const resultados = await resposta.json();

        ultimosResultadosBusca = Array.isArray(resultados) ? resultados : [];
        renderizarResultadosBusca();
    } catch (erro) {
        console.error('Erro na busca de alunos:', erro);
    }
}

/** Redesenha a lista de resultados já buscada — chamada também depois de
 * adicionar alguém, só pra atualizar quem já está marcado como adicionado,
 * sem precisar buscar de novo nem fechar a lista. */
function renderizarResultadosBusca() {
    const conteiner = document.getElementById('resultados-busca-aluno');

    if (ultimosResultadosBusca.length === 0) {
        conteiner.innerHTML = '<div class="item-resultado" style="cursor:default; color:var(--admin-texto-suave);">Nenhum aluno encontrado.</div>';
        conteiner.style.display = 'block';
        return;
    }

    conteiner.innerHTML = ultimosResultadosBusca.map(a => {
        const jaAdicionado = alunosSelecionados.some(sel => sel.matricula === a.matricula);
        return `
            <div class="item-resultado" data-matricula="${escapeHtml(a.matricula)}" data-nome="${escapeHtml(a.nome)}" data-turma="${escapeHtml(a.turma)}"
                 style="${jaAdicionado ? 'cursor:default; color:var(--admin-texto-suave); background:var(--admin-fundo);' : ''}">
                ${jaAdicionado ? '✓ ' : ''}<strong>${escapeHtml(a.nome)}</strong> — matrícula ${escapeHtml(a.matricula)}, turma ${escapeHtml(a.turma)}
                ${jaAdicionado ? ' <em>(já adicionado)</em>' : ''}
            </div>`;
    }).join('');
    conteiner.style.display = 'block';

    conteiner.querySelectorAll('.item-resultado[data-matricula]').forEach(item => {
        const matricula = item.dataset.matricula;
        if (alunosSelecionados.some(sel => sel.matricula === matricula)) return; // já adicionado, não clicável
        item.addEventListener('click', () => {
            adicionarAluno({ matricula, nome: item.dataset.nome, turma: item.dataset.turma });
            renderizarResultadosBusca(); // mantém a lista aberta, só atualiza as marcas
        });
    });
}

function adicionarAluno(aluno) {
    if (alunosSelecionados.some(a => a.matricula === aluno.matricula)) {
        mostrarAviso('Este aluno já está na lista.', 'info', 2500);
        return;
    }
    alunosSelecionados.push(aluno);
    renderizarAlunosSelecionados();
}

async function removerAlunoSelecionado(matricula, nome) {
    const confirmado = await confirmarAcao(`Remover ${nome} desta atividade?`, 'Remover');
    if (!confirmado) return;
    alunosSelecionados = alunosSelecionados.filter(a => a.matricula !== matricula);
    renderizarAlunosSelecionados();
    renderizarResultadosBusca(); // se este aluno estiver na lista de busca aberta, volta a ficar clicável
}

function renderizarAlunosSelecionados() {
    const conteiner = document.getElementById('lista-alunos-selecionados');
    if (alunosSelecionados.length === 0) {
        conteiner.innerHTML = '<span class="admin-subtitulo" style="font-size:0.85rem;">Nenhum aluno adicionado ainda.</span>';
        return;
    }
    conteiner.innerHTML = alunosSelecionados.map(a => `
        <span class="admin-chip">
            ${escapeHtml(a.nome)} (${escapeHtml(a.matricula)}, T${escapeHtml(a.turma)})
            <button type="button" data-matricula="${escapeHtml(a.matricula)}" title="Remover">×</button>
        </span>`).join('');
    conteiner.querySelectorAll('button[data-matricula]').forEach(btn => {
        btn.addEventListener('click', () => {
            const aluno = alunosSelecionados.find(a => a.matricula === btn.dataset.matricula);
            removerAlunoSelecionado(btn.dataset.matricula, aluno ? aluno.nome : btn.dataset.matricula);
        });
    });
}

async function carregarAtividades() {
    const corpo = document.getElementById('corpo-atividades');
    try {
        const resposta = await fetch('api/atividades.php', { credentials: 'same-origin', cache: 'no-store' });
        if (tratarNaoAutenticado(resposta)) return;
        if (!resposta.ok) throw new Error('Erro ao carregar atividades.');
        atividadesCache = await resposta.json();
        renderizarAtividades();
    } catch (erro) {
        console.error(erro);
        corpo.innerHTML = '<tr><td colspan="5" class="admin-vazio">Não foi possível carregar as atividades.</td></tr>';
    }
}

function renderizarAtividades() {
    const corpo = document.getElementById('corpo-atividades');
    if (atividadesCache.length === 0) {
        corpo.innerHTML = '<tr><td colspan="5" class="admin-vazio">Nenhuma atividade cadastrada ainda.</td></tr>';
        return;
    }

    corpo.innerHTML = atividadesCache.map(a => `
        <tr>
            <td>
                <button type="button" class="admin-btn secundario pequeno" data-toggle="${escapeHtml(a.id)}">▾ ${escapeHtml(a.atividade)}</button>
            </td>
            <td>${a.valor > 0 ? '+' : ''}${formatarMoeda(a.valor)}</td>
            <td>${escapeHtml(a.data)}</td>
            <td>${a.alunos.length}</td>
            <td style="white-space:nowrap;">
                <button type="button" class="admin-btn secundario pequeno" data-editar="${escapeHtml(a.id)}">Editar</button>
                <button type="button" class="admin-btn perigo pequeno" data-remover="${escapeHtml(a.id)}">Remover</button>
            </td>
        </tr>
        <tr class="linha-detalhe" data-detalhe-de="${escapeHtml(a.id)}" style="display:none;">
            <td colspan="5" style="background:var(--admin-fundo);">
                ${a.alunos.length === 0 ? '<span class="admin-subtitulo">Nenhum aluno.</span>' : `
                <table class="admin-tabela">
                    <thead><tr><th>Nome</th><th>Matrícula</th><th>Turma</th></tr></thead>
                    <tbody>
                        ${a.alunos.map(al => `<tr><td>${escapeHtml(al.nome)}</td><td>${escapeHtml(al.matricula)}</td><td>${escapeHtml(al.turma)}${iconeHistoricoTurma(al.turmaHistorico)}</td></tr>`).join('')}
                    </tbody>
                </table>`}
            </td>
        </tr>
    `).join('');

    corpo.querySelectorAll('[data-toggle]').forEach(btn => {
        btn.addEventListener('click', () => {
            const linha = corpo.querySelector(`.linha-detalhe[data-detalhe-de="${CSS.escape(btn.dataset.toggle)}"]`);
            if (linha) linha.style.display = linha.style.display === 'none' ? 'table-row' : 'none';
        });
    });
    corpo.querySelectorAll('[data-editar]').forEach(btn => {
        btn.addEventListener('click', () => carregarParaEdicao(btn.dataset.editar));
    });
    corpo.querySelectorAll('[data-remover]').forEach(btn => {
        btn.addEventListener('click', () => removerAtividade(btn.dataset.remover));
    });
}

function carregarParaEdicao(id) {
    const atividade = atividadesCache.find(a => a.id === id);
    if (!atividade) return;

    document.getElementById('atividade-id').value = atividade.id;
    document.getElementById('atividade-nome').value = atividade.atividade;
    document.getElementById('atividade-valor').value = atividade.valor;
    document.getElementById('atividade-data').value = atividade.data;
    alunosSelecionados = atividade.alunos.map(a => ({ matricula: a.matricula, nome: a.nome, turma: a.turma }));
    renderizarAlunosSelecionados();
    document.getElementById('resultados-busca-aluno').style.display = 'none';
    document.getElementById('busca-aluno').value = '';

    document.getElementById('titulo-formulario').textContent = 'Editar atividade';
    document.getElementById('btn-cancelar-edicao').style.display = 'inline-block';
    document.querySelector('.admin-cartao').scrollIntoView({ behavior: 'smooth' });
}

function limparFormulario() {
    document.getElementById('atividade-id').value = '';
    document.getElementById('atividade-nome').value = '';
    document.getElementById('atividade-valor').value = '';
    document.getElementById('atividade-data').value = new Date().toISOString().slice(0, 10);
    alunosSelecionados = [];
    renderizarAlunosSelecionados();
    document.getElementById('resultados-busca-aluno').style.display = 'none';
    document.getElementById('busca-aluno').value = '';
    document.getElementById('titulo-formulario').textContent = 'Nova atividade';
    document.getElementById('btn-cancelar-edicao').style.display = 'none';
}

async function salvarAtividade(e) {
    e.preventDefault();
    const id = document.getElementById('atividade-id').value;
    const atividade = document.getElementById('atividade-nome').value.trim();
    const valor = Number(document.getElementById('atividade-valor').value);
    const data = document.getElementById('atividade-data').value;

    if (alunosSelecionados.length === 0) {
        mostrarAviso('Adicione pelo menos um aluno.', 'erro');
        return;
    }

    const corpo = {
        acao: id ? 'editar' : 'criar',
        id, atividade, valor, data,
        matriculas: alunosSelecionados.map(a => a.matricula),
    };

    try {
        const resposta = await fetch('api/atividades.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify(corpo)
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Não foi possível salvar.');

        mostrarAviso(resultado.mensagem, 'sucesso');
        limparFormulario();
        carregarAtividades();
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}

async function removerAtividade(id) {
    const confirmado = await confirmarAcao('Tem certeza que deseja remover esta atividade? Isso vai afetar o saldo de todos os alunos incluídos nela.', 'Remover');
    if (!confirmado) return;

    try {
        const resposta = await fetch('api/atividades.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao: 'remover', id })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Não foi possível remover.');
        mostrarAviso(resultado.mensagem, 'sucesso');
        carregarAtividades();
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}
