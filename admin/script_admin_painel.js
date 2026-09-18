window.addEventListener('DOMContentLoaded', async () => {
    try {
        const resposta = await fetch('api/painel.php', { credentials: 'same-origin', cache: 'no-store' });
        if (tratarNaoAutenticado(resposta)) return;
        if (!resposta.ok) throw new Error('Erro ao carregar painel.');
        const dados = await resposta.json();

        renderizarPedidosPendentes(dados.pedidosPendentes || []);
        renderizarAdminsPendentes(dados.adminsPendentes || []);

        // A seção de contas de aluno pendentes só faz sentido mostrar se o
        // site público realmente estiver exigindo aprovação — senão quase
        // toda conta apareceria aqui sem significar nada (veja o comentário
        // em admin/api/painel.php). Esse valor já vem no config do próprio
        // painel admin (window.adminConfigPromise) — nunca precisa buscar
        // o config_publica.php do site público separadamente por causa
        // disso.
        const config = await window.adminConfigPromise;
        if (config.exigirAprovacaoContaAluno) {
            renderizarContasPendentes(dados.contasPendentes || []);
        }
        if (config.exigirAprovacaoTrocaTurma) {
            renderizarTrocasTurmaPendentes(dados.trocasTurmaPendentes || []);
        }
    } catch (erro) {
        console.error('Erro ao carregar painel:', erro);
        document.getElementById('corpo-pedidos-pendentes').innerHTML =
            '<tr><td colspan="5" class="admin-vazio">Não foi possível carregar o painel.</td></tr>';
    }
});

function renderizarPedidosPendentes(pedidos) {
    const corpo = document.getElementById('corpo-pedidos-pendentes');
    if (pedidos.length === 0) {
        corpo.innerHTML = '<tr><td colspan="5" class="admin-vazio">Nenhum pedido pendente. 🎉</td></tr>';
        return;
    }
    corpo.innerHTML = pedidos.map(p => `
        <tr>
            <td>${linkAluno(p.matricula, escapeHtml(p.nomeAluno))} <span style="color:var(--admin-texto-suave);">(${escapeHtml(p.matricula)})</span></td>
            <td>${escapeHtml(p.item)}</td>
            <td>🪙 ${formatarMoeda(p.valor)}</td>
            <td>${escapeHtml(formatarDataHora(p.data))}</td>
            <td style="white-space:nowrap;">
                <button class="admin-btn sucesso pequeno" data-id="${escapeHtml(p.id)}" data-status="Aprovado">Aprovar</button>
                <button class="admin-btn perigo pequeno" data-id="${escapeHtml(p.id)}" data-status="Cancelado">Cancelar</button>
            </td>
        </tr>`).join('');

    corpo.querySelectorAll('button[data-status]').forEach(btn => {
        btn.addEventListener('click', () => atualizarStatusRapido(btn.dataset.id, btn.dataset.status));
    });
}

async function atualizarStatusRapido(id, status) {
    const confirmado = await confirmarAcao(`Marcar este pedido como "${status}"?`, 'Confirmar');
    if (!confirmado) return;

    try {
        const resposta = await fetch('api/pedidos.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao: 'atualizar_status', id, status })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro ao atualizar.');
        mostrarAviso(resultado.mensagem, 'sucesso');
        setTimeout(() => location.reload(), 800);
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}

function renderizarContasPendentes(contas) {
    const secao = document.getElementById('secao-contas-pendentes');
    const corpo = document.getElementById('corpo-contas-pendentes');
    if (contas.length === 0) return;
    secao.style.display = 'block';
    corpo.innerHTML = contas.map(c => `
        <tr><td>${linkAluno(c.matricula, escapeHtml(c.nome))}</td><td>${escapeHtml(c.matricula)}</td><td>${escapeHtml(c.turma)}</td></tr>
    `).join('');
}

function renderizarTrocasTurmaPendentes(trocas) {
    const secao = document.getElementById('secao-trocas-turma-pendentes');
    const corpo = document.getElementById('corpo-trocas-turma-pendentes');
    if (trocas.length === 0) return;
    secao.style.display = 'block';
    corpo.innerHTML = trocas.map(t => {
        const anterior = t.turmaAnterior
            ? `${escapeHtml(t.turmaAnterior)} (${escapeHtml(String(t.turmaAnteriorAno ?? '?'))})`
            : '(nenhuma)';
        const marca = t.aprovacaoForcada ? ' ⚠️' : '';
        return `
        <tr>
            <td>${linkAluno(t.matricula, escapeHtml(t.nome))}</td>
            <td>${escapeHtml(t.matricula)}</td>
            <td>${anterior}</td>
            <td>${escapeHtml(t.turmaSolicitada)} (${escapeHtml(String(t.ano))})${marca}</td>
            <td>${escapeHtml(formatarDataHora(t.data))}</td>
            <td style="white-space:nowrap;">
                <button class="admin-btn secundario pequeno" data-id="${escapeHtml(t.id)}">Revisar</button>
            </td>
        </tr>`;
    }).join('');

    corpo.querySelectorAll('button[data-id]').forEach(btn => {
        const troca = trocas.find(t => t.id === btn.dataset.id);
        btn.addEventListener('click', () => abrirModalDecisaoTurma(troca));
    });
}

function renderizarAdminsPendentes(admins) {
    const secao = document.getElementById('secao-admins-pendentes');
    const corpo = document.getElementById('corpo-admins-pendentes');
    if (admins.length === 0) return;
    secao.style.display = 'block';
    corpo.innerHTML = admins.map(a => `
        <tr><td>${escapeHtml(a.nome)}</td><td>${escapeHtml(a.email)}</td></tr>
    `).join('');
}
