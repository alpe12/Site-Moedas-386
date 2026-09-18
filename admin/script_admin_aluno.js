let debounceBuscaPerfil = null;
let ultimosResultadosBuscaPerfil = [];

window.addEventListener('DOMContentLoaded', () => {
    const campoBusca = document.getElementById('busca-aluno-perfil');
    const conteinerResultados = document.getElementById('resultados-busca-aluno-perfil');

    campoBusca.addEventListener('input', (e) => {
        clearTimeout(debounceBuscaPerfil);
        const termo = e.target.value.trim();
        if (!termo) { conteinerResultados.style.display = 'none'; return; }
        debounceBuscaPerfil = setTimeout(() => buscarAlunosPerfil(termo), 300);
    });

    document.addEventListener('click', (e) => {
        if (!conteinerResultados.contains(e.target) && e.target !== campoBusca) {
            conteinerResultados.style.display = 'none';
        }
    });

    // Chegar aqui com ?matricula=XXXX na URL (ex.: link vindo de
    // atividades.html ou pedidos.html) já carrega o perfil direto, sem
    // precisar buscar de novo.
    const matriculaInicial = new URLSearchParams(location.search).get('matricula');
    if (matriculaInicial) {
        campoBusca.value = matriculaInicial;
        carregarPerfil(matriculaInicial);
    }
});

async function buscarAlunosPerfil(termo) {
    const conteiner = document.getElementById('resultados-busca-aluno-perfil');
    try {
        const resposta = await fetch(`api/alunos.php?busca=${encodeURIComponent(termo)}`, { credentials: 'same-origin' });
        if (tratarNaoAutenticado(resposta)) return;
        const resultados = await resposta.json();
        ultimosResultadosBuscaPerfil = Array.isArray(resultados) ? resultados : [];
        renderizarResultadosBuscaPerfil();
    } catch (erro) {
        console.error('Erro na busca de alunos:', erro);
    }
}

function renderizarResultadosBuscaPerfil() {
    const conteiner = document.getElementById('resultados-busca-aluno-perfil');

    if (ultimosResultadosBuscaPerfil.length === 0) {
        conteiner.innerHTML = '<div class="item-resultado" style="cursor:default; color:var(--admin-texto-suave);">Nenhum aluno encontrado.</div>';
        conteiner.style.display = 'block';
        return;
    }

    conteiner.innerHTML = ultimosResultadosBuscaPerfil.map(a => `
        <div class="item-resultado" data-matricula="${escapeHtml(a.matricula)}">
            <strong>${escapeHtml(a.nome)}</strong> — matrícula ${escapeHtml(a.matricula)}, turma ${escapeHtml(a.turma)}
        </div>`).join('');
    conteiner.style.display = 'block';

    conteiner.querySelectorAll('[data-matricula]').forEach(item => {
        item.addEventListener('click', () => {
            conteiner.style.display = 'none';
            document.getElementById('busca-aluno-perfil').value = item.dataset.matricula;
            carregarPerfil(item.dataset.matricula);
        });
    });
}

async function carregarPerfil(matricula) {
    const estadoPerfil = document.getElementById('estado-perfil');
    const perfil = document.getElementById('perfil-aluno');

    estadoPerfil.innerHTML = '<div class="admin-cartao"><p class="admin-vazio">Carregando...</p></div>';
    estadoPerfil.style.display = 'block';
    perfil.style.display = 'none';

    try {
        const resposta = await fetch(`api/aluno_detalhe.php?matricula=${encodeURIComponent(matricula)}`, { credentials: 'same-origin', cache: 'no-store' });
        if (tratarNaoAutenticado(resposta)) return;
        const dados = await resposta.json();
        if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível carregar este aluno.');

        // Reflete na URL pra dar pra voltar/compartilhar o link direto com
        // outro admin.
        const url = new URL(location.href);
        url.searchParams.set('matricula', dados.aluno.matricula);
        history.replaceState(null, '', url);

        renderizarPerfil(dados);
        estadoPerfil.style.display = 'none';
        perfil.style.display = 'block';
    } catch (erro) {
        estadoPerfil.innerHTML = `<div class="admin-cartao"><p class="admin-vazio">${escapeHtml(erro.message)}</p></div>`;
        estadoPerfil.style.display = 'block';
        perfil.style.display = 'none';
    }
}

function renderizarPerfil(dados) {
    const aluno = dados.aluno;

    // A coluna "ativo" só significa alguma coisa quando a aprovação manual
    // de contas está ligada no site público — mesmo raciocínio de
    // conta_esta_ativa() (api/_bootstrap.php), que o painel admin não
    // consegue chamar diretamente por isolamento (veja o comentário em
    // admin/api/aluno_detalhe.php).
    const contaAtiva = !aluno.exigeAprovacaoConta || aluno.ativoBruto;

    document.getElementById('perfil-nome').textContent = aluno.nome || '(sem nome)';
    const turmaAtualTexto = dados.turmaAtual
        ? `${dados.turmaAtual.turma} (${dados.turmaAtual.ano})`
        : 'sem turma atual';
    document.getElementById('perfil-subtitulo').textContent =
        `Matrícula ${aluno.matricula} · ${aluno.email || 'sem e-mail'} · Turma atual: ${turmaAtualTexto}`;

    document.getElementById('perfil-tags').innerHTML = contaAtiva
        ? '<span class="admin-tag ativo">Conta ativa</span>'
        : '<span class="admin-tag pendente">Pendente de aprovação</span>';

    const f = dados.financeiro || { ganho: 0, gasto: 0, saldo: 0, expiracoes: [] };
    document.getElementById('perfil-ganho').textContent = `🪙 ${formatarMoeda(f.ganho)}`;
    document.getElementById('perfil-gasto').textContent = `🪙 ${formatarMoeda(f.gasto)}`;
    document.getElementById('perfil-saldo').textContent = `🪙 ${formatarMoeda(f.saldo)}`;

    const elExpiracoes = document.getElementById('perfil-expiracoes');
    if (f.expiracoes && f.expiracoes.length > 0) {
        elExpiracoes.style.display = 'block';
        elExpiracoes.innerHTML = '⏳ Saldo expirado no início do ano: ' + f.expiracoes.map(e =>
            `${escapeHtml(String(e.ano))} (🪙 ${formatarMoeda(Math.abs(e.valor))})`
        ).join(', ');
    } else {
        elExpiracoes.style.display = 'none';
    }

    // --- Dados de cadastro ---
    const cadastroTexto = dados.cadastroAproximado
        ? `${formatarDataHora(dados.cadastroAproximado)} <span class="admin-subtitulo" style="font-size:0.75rem;">(aproximado — data da primeira turma registrada no cadastro; não existe uma data de cadastro gravada diretamente)</span>`
        : '<span class="admin-subtitulo">Não disponível (aluno sem nenhuma turma registrada).</span>';

    document.getElementById('tabela-cadastro').innerHTML = `
        <tr><th style="width:30%;">Matrícula</th><td>${escapeHtml(aluno.matricula)}</td></tr>
        <tr><th>Nome</th><td>${escapeHtml(aluno.nome)}</td></tr>
        <tr><th>E-mail</th><td>${escapeHtml(aluno.email)}</td></tr>
        <tr><th>Conta</th><td>${contaAtiva ? '<span class="admin-tag ativo">Ativa</span>' : '<span class="admin-tag pendente">Pendente de aprovação</span>'}</td></tr>
        <tr><th>Cadastrado em</th><td>${cadastroTexto}</td></tr>
        <tr><th>Código de recuperação de senha</th><td><code>${escapeHtml(aluno.resetToken || '—')}</code> <span class="admin-subtitulo" style="font-size:0.75rem;">(mostre ao aluno se ele perder o código, pra redefinir a senha)</span></td></tr>
    `;

    // --- Histórico de turmas ---
    const historico = dados.turmaHistorico || [];
    document.getElementById('tabela-turmas').innerHTML = historico.length === 0
        ? '<tr><td colspan="4" class="admin-vazio">Nenhuma turma registrada.</td></tr>'
        : historico.map(h => `
            <tr>
                <td>${escapeHtml(h.turma)}</td>
                <td>${escapeHtml(String(h.ano))}</td>
                <td>${formatarDataHora(h.data)}</td>
                <td>${escapeHtml(rotuloStatusTurma(h.status))}${h.retroativo ? ' <span title="Contando desde 1º de janeiro">↩️ retroativo</span>' : ''}${h.retroativoPendente ? ' <span title="Ainda não foi decidido se conta retroativo">⏳</span>' : ''}${h.aprovacaoForcada ? ' <span title="Esta troca exigiu aprovação manual mesmo com o auto-aplicar geral ligado">🔒</span>' : ''}</td>
            </tr>`).join('');

    // --- Atividades ---
    const atividades = dados.atividades || [];
    document.getElementById('tabela-atividades').innerHTML = atividades.length === 0
        ? '<tr><td colspan="4" class="admin-vazio">Nenhuma atividade lançada pra este aluno ainda.</td></tr>'
        : atividades.map(a => `
            <tr>
                <td>${escapeHtml(a.data)}</td>
                <td>${escapeHtml(a.atividade)}${a.quantidadeAlunosNaLinha > 1 ? ` <span class="admin-subtitulo" style="font-size:0.75rem;" title="Este lançamento creditou ${a.quantidadeAlunosNaLinha} alunos de uma vez">(turma, ${a.quantidadeAlunosNaLinha} alunos)</span>` : ''}</td>
                <td style="color:${a.valor < 0 ? 'var(--admin-vermelho)' : 'inherit'};">${a.valor > 0 ? '+' : ''}${formatarMoeda(a.valor)}</td>
                <td>${escapeHtml(a.turma)}</td>
            </tr>`).join('');

    // --- Pedidos ---
    const CLASSES_STATUS = { Pendente: 'pendente', Aprovado: 'aprovado', Resgatado: 'resgatado', Cancelado: 'cancelado' };
    const pedidos = dados.pedidos || [];
    document.getElementById('tabela-pedidos').innerHTML = pedidos.length === 0
        ? '<tr><td colspan="5" class="admin-vazio">Nenhum pedido feito por este aluno ainda.</td></tr>'
        : pedidos.map(p => `
            <tr>
                <td>${escapeHtml(formatarDataHora(p.data))}</td>
                <td>${escapeHtml(p.item)}</td>
                <td>🪙 ${formatarMoeda(p.valor)}</td>
                <td><span class="admin-tag ${CLASSES_STATUS[p.status] || ''}">${escapeHtml(p.status)}</span></td>
                <td>${escapeHtml(p.turma)}</td>
            </tr>`).join('');
}
