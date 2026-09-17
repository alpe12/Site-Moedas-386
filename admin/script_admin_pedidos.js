window.addEventListener('DOMContentLoaded', () => {
    document.getElementById('filtro-status').addEventListener('change', carregarPedidos);
    carregarPedidos();
});

const CLASSES_STATUS = { Pendente: 'pendente', Aprovado: 'aprovado', Resgatado: 'resgatado', Cancelado: 'cancelado' };

async function carregarPedidos() {
    const corpo = document.getElementById('corpo-pedidos');
    const status = document.getElementById('filtro-status').value;
    try {
        const resposta = await fetch(`api/pedidos.php?status=${encodeURIComponent(status)}`, { credentials: 'same-origin', cache: 'no-store' });
        if (tratarNaoAutenticado(resposta)) return;
        if (!resposta.ok) throw new Error('Erro ao carregar pedidos.');
        const pedidos = await resposta.json();
        renderizarPedidos(Array.isArray(pedidos) ? pedidos : []);
    } catch (erro) {
        console.error(erro);
        corpo.innerHTML = '<tr><td colspan="7" class="admin-vazio">Não foi possível carregar os pedidos.</td></tr>';
    }
}

function renderizarPedidos(pedidos) {
    const corpo = document.getElementById('corpo-pedidos');
    if (pedidos.length === 0) {
        corpo.innerHTML = '<tr><td colspan="7" class="admin-vazio">Nenhum pedido encontrado.</td></tr>';
        return;
    }

    const opcoesStatus = ['Pendente', 'Aprovado', 'Resgatado', 'Cancelado'];

    corpo.innerHTML = pedidos.map(p => `
        <tr>
            <td>${escapeHtml(p.nomeAluno)} <span style="color:var(--admin-texto-suave);">(${escapeHtml(p.matricula)})</span></td>
            <td>${escapeHtml(p.turma)}${iconeHistoricoTurma(p.turmaHistorico)}</td>
            <td>${escapeHtml(p.item)}</td>
            <td>🪙 ${formatarMoeda(p.valor)}</td>
            <td>${escapeHtml(formatarDataHora(p.data))}</td>
            <td><span class="admin-tag ${CLASSES_STATUS[p.status] || ''}">${escapeHtml(p.status)}</span></td>
            <td>
                <select data-id="${escapeHtml(p.id)}" style="width:auto; padding:5px 8px; font-size:0.8rem;">
                    ${opcoesStatus.map(s => `<option value="${s}" ${s === p.status ? 'selected' : ''}>${s}</option>`).join('')}
                </select>
            </td>
        </tr>
    `).join('');

    corpo.querySelectorAll('select[data-id]').forEach(sel => {
        sel.addEventListener('change', () => atualizarStatus(sel.dataset.id, sel.value));
    });
}

async function atualizarStatus(id, novoStatus) {
    const confirmado = await confirmarAcao(`Alterar o status deste pedido para "${novoStatus}"?`, 'Confirmar');
    if (!confirmado) { carregarPedidos(); return; }

    try {
        const resposta = await fetch('api/pedidos.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao: 'atualizar_status', id, status: novoStatus })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro ao atualizar.');
        mostrarAviso(resultado.mensagem, 'sucesso');
        carregarPedidos();
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
        carregarPedidos();
    }
}
