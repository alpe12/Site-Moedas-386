window.addEventListener('DOMContentLoaded', async () => {
    // Se já tem uma sessão válida, não faz sentido mostrar a tela de login de novo.
    try {
        const respostaSessao = await fetch('api/sessao.php', { credentials: 'same-origin', cache: 'no-store' });
        if (respostaSessao.ok) {
            const status = await respostaSessao.json();
            if (status.autenticado) { window.location.href = 'painel.html'; return; }
        }
    } catch {
        // Se a checagem falhar, só segue pra tela de login normalmente.
    }

    const config = await window.adminConfigPromise;

    const formLogin = document.getElementById('form-login-admin');
    const formCadastro = document.getElementById('form-cadastro-admin');
    const formRecuperar = document.getElementById('form-recuperar-admin');
    const linkCadastro = document.getElementById('link-mostrar-cadastro');
    const linkRecuperar = document.getElementById('link-mostrar-recuperar');
    const linkLogin = document.getElementById('link-mostrar-login');
    const titulo = document.getElementById('admin-titulo');
    const grupoLembrarMe = document.getElementById('grupo-lembrar-me');

    if (!config.lembrarMeDisponivel && grupoLembrarMe) grupoLembrarMe.style.display = 'none';

    melhorarCampoSenha(document.getElementById('login-senha'));
    melhorarCampoSenha(document.getElementById('cad-senha'), { checklist: true, regras: config });
    melhorarCampoSenha(document.getElementById('cad-conf-senha'));
    melhorarCampoSenha(document.getElementById('rec-nova-senha'), { checklist: true, regras: config });

    function mostrarSomente(form) {
        [formLogin, formCadastro, formRecuperar].forEach(f => f.style.display = (f === form) ? 'block' : 'none');
        linkCadastro.style.display = form === formLogin ? 'inline' : 'none';
        linkRecuperar.style.display = form === formLogin ? 'inline' : 'none';
        linkLogin.style.display = form === formLogin ? 'none' : 'inline';
        titulo.textContent = form === formCadastro ? 'Criar conta administrativa'
            : form === formRecuperar ? 'Redefinir senha' : 'Painel Administrativo';
    }

    linkCadastro.addEventListener('click', () => mostrarSomente(formCadastro));
    linkRecuperar.addEventListener('click', () => mostrarSomente(formRecuperar));
    linkLogin.addEventListener('click', () => mostrarSomente(formLogin));

    formLogin.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('login-email').value.trim().toLowerCase();
        const senha = document.getElementById('login-senha').value;
        const lembrarMe = document.getElementById('login-lembrar-me').checked;

        try {
            const resposta = await fetch('auth.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ acao: 'login', email, senha, lembrarMe })
            });
            const resultado = await resposta.json();
            if (resultado.sucesso) {
                window.location.href = 'painel.html';
            } else {
                mostrarAviso(resultado.mensagem || 'E-mail ou senha incorretos.', 'erro');
            }
        } catch {
            mostrarAviso('Erro ao conectar com o servidor.', 'erro');
        }
    });

    function validarSenha(senha) {
        if (senha.length < config.senhaMinTamanho) return `A senha deve ter pelo menos ${config.senhaMinTamanho} caracteres.`;
        if (config.senhaMinLetras > 0 && (senha.match(/\p{L}/gu) || []).length < config.senhaMinLetras) return `A senha deve ter pelo menos ${config.senhaMinLetras} letra(s).`;
        if (config.senhaMinNumeros > 0 && (senha.match(/[0-9]/g) || []).length < config.senhaMinNumeros) return `A senha deve ter pelo menos ${config.senhaMinNumeros} número(s).`;
        if (config.senhaMinEspeciais > 0 && (senha.match(/[^\p{L}0-9]/gu) || []).length < config.senhaMinEspeciais) return `A senha deve ter pelo menos ${config.senhaMinEspeciais} caractere(s) especial(is).`;
        return null;
    }

    formCadastro.addEventListener('submit', async (e) => {
        e.preventDefault();
        const nome = document.getElementById('cad-nome').value.trim();
        const email = document.getElementById('cad-email').value.trim().toLowerCase();
        const senha = document.getElementById('cad-senha').value;
        const confSenha = document.getElementById('cad-conf-senha').value;
        const token = document.getElementById('cad-token').value.trim();

        if (senha !== confSenha) { mostrarAviso('As senhas não coincidem!', 'erro'); return; }
        const erroSenha = validarSenha(senha);
        if (erroSenha) { mostrarAviso(erroSenha, 'erro'); return; }

        try {
            const resposta = await fetch('auth.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ acao: 'cadastro', nome, email, senha, token })
            });
            const resultado = await resposta.json();
            if (resultado.sucesso) {
                mostrarAviso(resultado.contaAtiva === false
                    ? 'Conta criada! Ela está pendente de aprovação — peça pra alguém trocar "ativo" pra 1 em admin/.private/admins.csv.'
                    : 'Conta criada com sucesso! Faça login.', 'sucesso', 8000);
                mostrarSomente(formLogin);
            } else {
                mostrarAviso(resultado.mensagem || 'Não foi possível criar a conta.', 'erro');
            }
        } catch {
            mostrarAviso('Erro ao conectar com o servidor.', 'erro');
        }
    });

    formRecuperar.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('rec-email').value.trim().toLowerCase();
        const token = document.getElementById('rec-token').value.trim();
        const novaSenha = document.getElementById('rec-nova-senha').value;

        const erroSenha = validarSenha(novaSenha);
        if (erroSenha) { mostrarAviso(erroSenha, 'erro'); return; }

        try {
            const resposta = await fetch('auth.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ acao: 'redefinirSenha', email, token, novaSenha })
            });
            const resultado = await resposta.json();
            if (resultado.sucesso) {
                mostrarAviso('Senha alterada! Faça login.', 'sucesso');
                mostrarSomente(formLogin);
            } else {
                mostrarAviso(resultado.mensagem || 'Não foi possível redefinir a senha.', 'erro');
            }
        } catch {
            mostrarAviso('Erro ao conectar com o servidor.', 'erro');
        }
    });
});
