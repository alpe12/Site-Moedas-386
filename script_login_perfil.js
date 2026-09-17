// ==========================================
// AUTENTICAÇÃO E PERFIL
// A identidade do aluno é mantida exclusivamente pela sessão PHP.
// O servidor mantém a sessão PHP em cookie HttpOnly.
// ==========================================

window.addEventListener('DOMContentLoaded', async () => {
    const pagina = document.body.id;

    if (pagina === "pagina_login") {
        await inicializarLogin();
        return;
    }

    if (pagina === "pagina_perfil") {
        await inicializarPerfil();
        return;
    }

    if (pagina === "pagina_recuperar") {
        await inicializarRecuperar();
    }
});

async function inicializarRecuperar() {
    const config = await window.configPromise;

    const campoMatricula = document.getElementById("rec_matricula");
    if (campoMatricula) {
        campoMatricula.maxLength = config.matriculaTamanho;
        restringirSomenteNumeros(campoMatricula, "Matrícula");
    }

    const linkWhatsapp = document.getElementById("link-whatsapp-recuperar");
    if (linkWhatsapp && config.whatsappLink) linkWhatsapp.href = config.whatsappLink;

    const novaSenha = document.getElementById("nova_senha");
    if (novaSenha) melhorarCampoSenha(novaSenha, { checklist: true, regras: config });
}

async function inicializarPerfil() {
    configurarTrocaTurma();
    await carregarPerfil();
}

async function carregarPerfil() {
    try {
        const resposta = await fetch('api/profile.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (resposta.status === 401) {
            window.location.href = 'login.html';
            return;
        }

        if (!resposta.ok) throw new Error("Não foi possível carregar o perfil.");
        const dadosAluno = await resposta.json();
        renderizarPerfil(dadosAluno);
    } catch (erro) {
        console.error("Erro ao carregar perfil:", erro);
        document.getElementById("nomeAluno").innerText = "Erro de conexão";
        document.getElementById("saldoAluno").innerText = "Não foi possível carregar seu perfil.";
    }
}

async function inicializarLogin() {
    const config = await window.configPromise;

    const botao_conta = document.getElementById("btn_conta");
    const formLogin = document.getElementById("login");
    const formCadastro = document.getElementById("nova_conta");

    if (botao_conta) botao_conta.addEventListener("click", trocaPaginaCadastro);

    // Usar o evento "submit" (em vez de "click" no botão) permite enviar o
    // formulário apertando Enter em qualquer campo, como o usuário espera.
    if (formLogin) formLogin.addEventListener("submit", (evento) => { evento.preventDefault(); fazerLogin(); });
    if (formCadastro) formCadastro.addEventListener("submit", (evento) => { evento.preventDefault(); fazerCadastro(config); });

    // Impede digitar o que não é permitido em cada campo, em vez de só
    // reclamar depois de enviar o formulário.
    const campoMatricula = document.getElementById("matricula_cadastro");
    const campoTurma = document.getElementById("turma_cadastro");
    const campoNome = document.getElementById("nome_cadastro");
    if (campoMatricula) {
        campoMatricula.maxLength = config.matriculaTamanho;
        restringirSomenteNumeros(campoMatricula, "Matrícula");
    }
    if (campoTurma) {
        campoTurma.maxLength = config.turmaTamanho;
        restringirSomenteNumeros(campoTurma, "Turma");
    }
    if (campoNome) restringirSomenteLetras(campoNome, "Nome completo");

    // Mostrar/ocultar em todo campo de senha; a listinha de regras que
    // acende verde/vermelho só faz sentido onde a senha está sendo
    // definida de novo (cadastro), não no login.
    const senhaLogin = document.getElementById("senha_login");
    if (senhaLogin) melhorarCampoSenha(senhaLogin);
    const senhaCadastro = document.getElementById("senha_cadastro");
    if (senhaCadastro) melhorarCampoSenha(senhaCadastro, { checklist: true, regras: config });
    const confSenha = document.getElementById("confsenha");
    if (confSenha) melhorarCampoSenha(confSenha);

    const linkWhatsapp = document.getElementById("link-whatsapp-login");
    if (linkWhatsapp && config.whatsappLink) linkWhatsapp.href = config.whatsappLink;
}

function trocaPaginaCadastro() {
    const titulo = document.getElementById("titulo");
    const botao_conta = document.getElementById("btn_conta");
    const login = document.getElementById("login");
    const novaConta = document.getElementById("nova_conta");

    if (login.style.display !== "none") {
        login.style.display = "none";
        novaConta.style.display = "block";
        if (botao_conta) botao_conta.textContent = "Já tenho conta";
        if (titulo) titulo.textContent = "Nova conta";
    } else {
        login.style.display = "block";
        novaConta.style.display = "none";
        if (botao_conta) botao_conta.textContent = "Não tenho conta";
        if (titulo) titulo.textContent = "Acesse sua conta";
    }
}

async function fazerLogin() {
    const email = document.getElementById("email_login").value.trim().toLowerCase();
    const senha = document.getElementById("senha_login").value;

    if (!email || !senha) {
        mostrarAviso("Por favor, informe seu e-mail e sua senha.", 'erro');
        return;
    }

    try {
        const resposta = await fetch('auth.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ acao: "login", email, senha })
        });

        const resultado = await resposta.json();

        if (resultado.sucesso) {
            window.location.href = 'perfil.html';
        } else {
            mostrarAviso(resultado.mensagem || "E-mail ou senha incorretos.", 'erro');
        }
    } catch (erro) {
        console.error("Erro ao realizar login:", erro);
        mostrarAviso("Erro ao conectar com o servidor. Tente novamente.", 'erro');
    }
}

/** Validação no navegador, espelhando as mesmas regras que o servidor aplica (config.php). */
function validarCadastro(campos, config) {
    const palavrasNome = campos.nome.split(/\s+/).filter(Boolean);
    if (palavrasNome.length < 2) return "Digite o nome completo (nome e sobrenome).";

    if (!new RegExp(`^\\d{${config.turmaTamanho}}$`).test(campos.turma)) {
        return `A turma deve ter exatamente ${config.turmaTamanho} números.`;
    }

    if (!new RegExp(`^\\d{${config.matriculaTamanho}}$`).test(campos.matricula)) {
        return "Matrícula inválida.";
    }
    const ano = Number(campos.matricula.slice(0, 4));
    if (ano < config.matriculaAnoMin || ano > config.matriculaAnoMax) {
        return "Matrícula inválida.";
    }

    if (!campos.email.includes('@')) return "E-mail inválido.";

    return validarSenha(campos.senha, config);
}

function validarSenha(senha, config) {
    if (senha.length < config.senhaMinTamanho) return `A senha deve ter pelo menos ${config.senhaMinTamanho} caracteres.`;
    if (config.senhaMinLetras > 0 && (senha.match(/\p{L}/gu) || []).length < config.senhaMinLetras) {
        return `A senha deve ter pelo menos ${config.senhaMinLetras} letra(s).`;
    }
    if (config.senhaMinNumeros > 0 && (senha.match(/[0-9]/g) || []).length < config.senhaMinNumeros) {
        return `A senha deve ter pelo menos ${config.senhaMinNumeros} número(s).`;
    }
    if (config.senhaMinEspeciais > 0 && (senha.match(/[^\p{L}0-9]/gu) || []).length < config.senhaMinEspeciais) {
        return `A senha deve ter pelo menos ${config.senhaMinEspeciais} caractere(s) especial(is).`;
    }
    return null;
}

async function fazerCadastro(config) {
    const nome = document.getElementById("nome_cadastro").value.trim();
    const matricula = document.getElementById("matricula_cadastro").value.trim();
    const turma = document.getElementById("turma_cadastro").value.trim();
    const email = document.getElementById("email_cadastro").value.trim().toLowerCase();
    const senha = document.getElementById("senha_cadastro").value;
    const confSenha = document.getElementById("confsenha").value;

    if (!nome || !matricula || !turma || !email || !senha) {
        mostrarAviso("Por favor, preencha todos os campos do cadastro.", 'erro');
        return;
    }

    if (senha !== confSenha) {
        mostrarAviso("As senhas não coincidem!", 'erro');
        return;
    }

    const erroValidacao = validarCadastro({ nome, matricula, turma, email, senha }, config);
    if (erroValidacao) {
        mostrarAviso(erroValidacao, 'erro');
        return;
    }

    try {
        const resposta = await fetch('auth.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
                acao: "cadastro", nome, matricula, turma, email, senha
            })
        });

        const resultado = await resposta.json();

        if (resultado.sucesso) {
            mostrarModalToken(resultado.tokenRecuperacao, resultado.contaAtiva !== false);
        } else {
            mostrarAviso(resultado.mensagem || "Não foi possível realizar o cadastro.", 'erro');
        }
    } catch (erro) {
        console.error("Erro no cadastro:", erro);
        mostrarAviso("Erro de conexão ao realizar o cadastro.", 'erro');
    }
}

/**
 * Mostra o código de recuperação de senha uma única vez, logo após o
 * cadastro. Diferente de mostrarAviso(), fica na tela até o aluno confirmar
 * que anotou — este código nunca mais será exibido depois disso.
 */
function mostrarModalToken(token, contaAtiva) {
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 10000;
        display: flex; align-items: center; justify-content: center; padding: 20px;`;

    const avisoConta = contaAtiva
        ? ''
        : `<p style="color:#b45309; background:#fef3c7; padding:10px 14px; border-radius:8px; font-size:0.9rem; margin-top:14px;">
             Sua conta foi criada, mas ainda precisa ser aprovada antes de aparecer no ranking ou fazer resgates.
           </p>`;

    overlay.innerHTML = `
        <div style="background:#fff; border-radius:16px; padding:32px; max-width:400px; width:100%; text-align:center; box-shadow: 0 20px 40px rgba(0,0,0,0.2);">
            <h2 style="margin-bottom: 8px;">Cadastro realizado! 🎉</h2>
            <p style="color:#475569; font-size:0.95rem; margin-bottom: 16px;">
                Guarde este código. Ele será pedido se você esquecer sua senha, e <strong>não será mostrado de novo</strong>.
            </p>
            <div style="font-size:2rem; font-weight:800; letter-spacing:6px; background:#f1f5f9; border-radius:10px; padding:16px; margin-bottom:16px;">
                ${escapeHtml(token || '------')}
            </div>
            <div style="display:flex; gap:10px; justify-content:center;">
                <button id="btn-copiar-token" class="btn-entrar" style="flex:1;">Copiar código</button>
                <button id="btn-fechar-token" class="btn-entrar" style="flex:1; background:#475569;">Entendi</button>
            </div>
            ${avisoConta}
        </div>`;

    document.body.appendChild(overlay);

    overlay.querySelector('#btn-copiar-token').addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(token || '');
            mostrarAviso('Código copiado!', 'sucesso', 2000);
        } catch {
            mostrarAviso('Não foi possível copiar automaticamente — anote o código manualmente.', 'erro');
        }
    });

    const fechar = () => { overlay.remove(); trocaPaginaCadastro(); };
    overlay.querySelector('#btn-fechar-token').addEventListener('click', fechar);
}

function formatarData(dataJSON) {
    if (!dataJSON) return "";
    const data = new Date(dataJSON);
    if (isNaN(data.getTime())) return String(dataJSON);
    let texto = data.toLocaleDateString("pt-BR", {
        weekday: "long",
        day: "2-digit",
        month: "2-digit",
        year: "2-digit"
    });
    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

let trocaTurmaPendenteAtual = null;

function renderizarPerfil(aluno) {
    if (!aluno || typeof aluno !== "object" || !aluno.nome) {
        document.getElementById("nomeAluno").innerText = "Perfil não encontrado";
        document.getElementById("saldoAluno").innerText = "Perfil do aluno não encontrado.";
        return;
    }

    document.getElementById("nomeAluno").innerText = aluno.nome || "Aluno";
    document.getElementById("turmaAluno").innerText = aluno.turma || "não definida";
    document.getElementById("matriculaAluno").innerText = aluno.matricula || "";
    document.getElementById("saldoAluno").innerText = `🪙 ${formatarMoeda(aluno.saldo)} EcoCoins`;

    // Três estados possíveis, nesta ordem de prioridade: (1) já existe uma
    // troca aguardando decisão — mostra o aviso com a turma pedida e
    // esconde o botão de solicitar outra (o servidor rejeitaria mesmo
    // assim, mas evita preencher o formulário só pra receber um erro);
    // (2) sem troca pendente, mas o aluno não tem turma válida pra ESTE
    // ano (aluno.turma vem "" nesse caso — veja api/profile.php) — pede
    // pra ele atualizar, com o formulário já aberto; (3) tudo normal.
    trocaTurmaPendenteAtual = aluno.trocaTurmaPendente || null;
    const botaoTrocar = document.getElementById("btnTrocarTurma");
    const avisoPendente = document.getElementById("avisoTrocaTurmaPendente");
    const avisoSemTurma = document.getElementById("avisoSemTurmaEsteAno");
    const form = document.getElementById("formTrocarTurma");

    if (trocaTurmaPendenteAtual) {
        if (botaoTrocar) botaoTrocar.style.display = "none";
        if (avisoPendente) {
            avisoPendente.style.display = "inline-block";
            const alvo = document.getElementById("turmaSolicitadaPendente");
            if (alvo) alvo.innerText = `${trocaTurmaPendenteAtual.turma} (${trocaTurmaPendenteAtual.ano})`;
        }
        if (avisoSemTurma) avisoSemTurma.style.display = "none";
        if (form) form.style.display = "none";
    } else {
        if (avisoPendente) avisoPendente.style.display = "none";
        if (!aluno.turma) {
            // Sem turma válida pra este ano — chama atenção e já abre o
            // formulário, em vez de exigir mais um clique.
            if (avisoSemTurma) avisoSemTurma.style.display = "inline-block";
            if (botaoTrocar) botaoTrocar.style.display = "none";
            if (form) form.style.display = "flex";
        } else {
            if (avisoSemTurma) avisoSemTurma.style.display = "none";
            if (botaoTrocar) botaoTrocar.style.display = "";
        }
    }

    if (aluno.contaAtiva === false) {
        mostrarAviso('Sua conta está pendente de aprovação. Você ainda não aparece no ranking nem pode fazer resgates.', 'info', 8000);
    }

    const tbody = document.getElementById("atividadesAluno");
    if (!tbody) return;

    const atividades = Array.isArray(aluno.atividades) ? aluno.atividades : [];
    if (atividades.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:var(--text-gray, #666); padding:16px;">
            Nenhuma atividade registrada ainda.</td></tr>`;
        return;
    }

    tbody.innerHTML = atividades.map(a => {
        const valor = Number(a.valor) || 0;
        return `
            <tr>
                <td>${escapeHtml(a.atividade)}</td>
                <td>${escapeHtml(formatarData(a.data))}</td>
                <td>${valor > 0 ? "+" : ""}${formatarMoeda(valor)}</td>
            </tr>`;
    }).join('');
}

/**
 * Liga os botões de "Trocar de turma" em perfil.html. Chamada uma vez só,
 * antes mesmo do perfil carregar (não depende dos dados do aluno) — quem
 * decide se o botão aparece ou não é renderizarPerfil() (baseado em
 * trocaTurmaPendente), não esta função.
 */
function configurarTrocaTurma() {
    const botaoTrocar = document.getElementById("btnTrocarTurma");
    const form = document.getElementById("formTrocarTurma");
    const campoTurma = document.getElementById("novaTurma");
    const botaoCancelar = document.getElementById("btnCancelarTrocaTurma");
    const botaoConfirmar = document.getElementById("btnConfirmarTrocaTurma");
    const botaoCancelarPendente = document.getElementById("btnCancelarSolicitacaoPendente");
    if (!botaoTrocar || !form || !campoTurma) return;

    window.configPromise.then(config => {
        campoTurma.maxLength = config.turmaTamanho;
    });
    restringirSomenteNumeros(campoTurma, "Turma");

    botaoTrocar.addEventListener("click", () => {
        form.style.display = "flex";
        botaoTrocar.style.display = "none";
        campoTurma.value = "";
        campoTurma.focus();
    });

    botaoCancelar.addEventListener("click", () => {
        form.style.display = "none";
        botaoTrocar.style.display = "";
    });

    botaoConfirmar.addEventListener("click", () => solicitarTrocaTurma());
    botaoCancelarPendente?.addEventListener("click", () => cancelarSolicitacaoTurmaPendente());
}

async function solicitarTrocaTurma() {
    const campoTurma = document.getElementById("novaTurma");
    const turma = campoTurma.value.trim();
    const config = await window.configPromise;

    if (!new RegExp(`^\\d{${config.turmaTamanho}}$`).test(turma)) {
        mostrarAviso(`A turma deve ter exatamente ${config.turmaTamanho} números.`, 'erro');
        return;
    }

    if (!confirm(`Confirmar que você está matriculado(a) na turma "${turma}" atualmente?`)) return;

    const botaoConfirmar = document.getElementById("btnConfirmarTrocaTurma");
    botaoConfirmar.disabled = true;
    try {
        const resposta = await fetch('api/turma.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ turma })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Não foi possível enviar a solicitação.');

        mostrarAviso(resultado.mensagem, 'sucesso', 6000);
        document.getElementById("formTrocarTurma").style.display = "none";
        await carregarPerfil(); // recarrega o perfil pra refletir a turma nova (ou o aviso de pendente)
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    } finally {
        botaoConfirmar.disabled = false;
    }
}

async function cancelarSolicitacaoTurmaPendente() {
    if (!trocaTurmaPendenteAtual) return;
    const { id, turma, ano } = trocaTurmaPendenteAtual;

    if (!confirm(`Cancelar a solicitação de troca para a turma "${turma}" (${ano})?`)) return;

    const botao = document.getElementById("btnCancelarSolicitacaoPendente");
    if (botao) botao.disabled = true;
    try {
        const resposta = await fetch('api/turma.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ acao: 'cancelar', id })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Não foi possível cancelar a solicitação.');

        mostrarAviso(resultado.mensagem, 'sucesso');
        await carregarPerfil();
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    } finally {
        if (botao) botao.disabled = false;
    }
}

async function verificarERedefinir() {
    const config = await window.configPromise;

    const matricula = document.getElementById("rec_matricula").value.trim();
    const email = document.getElementById("rec_email").value.trim().toLowerCase();
    const token = document.getElementById("rec_token").value.trim().toUpperCase();
    const novaSenha = document.getElementById("nova_senha").value;

    if (!matricula || !email || !token || !novaSenha) {
        mostrarAviso("Por favor, preencha todos os campos!", 'erro');
        return;
    }

    const erroSenha = validarSenha(novaSenha, config);
    if (erroSenha) {
        mostrarAviso(erroSenha, 'erro');
        return;
    }

    try {
        const resposta = await fetch('auth.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
                acao: "redefinirSenha",
                matricula, email, token, novaSenha
            })
        });

        const resultado = await resposta.json();

        if (resultado.sucesso) {
            mostrarAviso("Senha alterada com sucesso!", 'sucesso');
            setTimeout(() => { window.location.href = 'login.html'; }, 1200);
        } else {
            mostrarAviso(resultado.mensagem || "Não foi possível alterar a senha.", 'erro');
        }
    } catch (erro) {
        console.error("Erro ao redefinir senha:", erro);
        mostrarAviso("Ocorreu um erro ao tentar redefinir a senha.", 'erro');
    }
}

async function sair() {
    try {
        await fetch('logout.php', {
            method: 'POST',
            credentials: 'same-origin'
        });
    } finally {
        window.location.href = 'login.html';
    }
}
