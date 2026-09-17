<?php
declare(strict_types=1);

// ============================================================
// CONFIGURAÇÃO DO PAINEL ADMINISTRATIVO
// Propositalmente separada de api/config.php (o site público) — o painel
// admin é isolado do site principal, então nenhuma constante daqui tem o
// mesmo nome de nada no config.php público (evita colisão caso algum dia
// os dois precisem ser carregados no mesmo processo).
// ============================================================

// --- Token mestre de administração ---
// Um único token compartilhado, usado tanto pra CRIAR uma conta admin nova
// quanto pra REDEFINIR a senha de uma conta admin existente. Fica guardado
// em texto puro em admin/.private/admin_token.txt (só o servidor consegue
// ler esse arquivo — é o "segredo físico" que autoriza as duas ações).
// Depois de qualquer uso bem-sucedido (criação OU redefinição), um token
// novo é gerado sozinho: quem precisar fazer outra ação vai precisar olhar
// o arquivo de novo pra pegar o token atualizado. Uma tentativa com token
// errado nunca gasta/invalida o token válido atual.
const ADMIN_TOKEN_TAMANHO = 32;

// --- Aprovação manual de contas admin ---
// true  -> conta admin nova nasce com ativo=0 e não consegue logar até
//          alguém trocar pra 1 em admin/.private/admins.csv.
// false -> (padrão) conta admin nasce já podendo logar assim que criada
//          (a posse do token mestre já é, em si, uma barreira forte).
const EXIGIR_APROVACAO_ADMIN = false;

// --- Duração do login admin ---
// Sem marcar "lembrar-me": expira por inatividade depois deste tempo
// (janela deslizante, como no site público).
const ADMIN_LOGIN_DURACAO_SEGUNDOS = 3600; // 1 hora
// Marcando "lembrar-me" no login: expira por inatividade depois deste
// tempo, bem maior, em vez do padrão acima.
const ADMIN_LOGIN_LEMBRAR_SEGUNDOS = 48 * 3600; // 48 horas
// A opção "lembrar-me" só aparece na tela de login se ela realmente durar
// mais que o padrão — não existe (nem precisa existir) uma config separada
// só pra desativá-la: configure os dois valores acima iguais (ou o
// "lembrar" menor ou igual ao padrão) que ela some sozinha da tela.
const ADMIN_LEMBRAR_ME_DISPONIVEL = ADMIN_LOGIN_LEMBRAR_SEGUNDOS > ADMIN_LOGIN_DURACAO_SEGUNDOS;

// --- Trava de escrita concorrente em CSV (mesma ideia do site público) ---
const LOCK_TIMEOUT_SEGUNDOS = 10;

// --- Regras de senha do admin (podem ser mais rígidas que as do aluno) ---
const ADMIN_SENHA_MIN_TAMANHO = 10;
const ADMIN_SENHA_MIN_LETRAS = 0;
const ADMIN_SENHA_MIN_NUMEROS = 0;
const ADMIN_SENHA_MIN_ESPECIAIS = 0;

/** Valida uma senha de admin contra as regras acima. Null = ok, senão a mensagem de erro. */
function validar_senha_admin(string $senha): ?string {
    if (mb_strlen($senha) < ADMIN_SENHA_MIN_TAMANHO) {
        return "A senha deve ter pelo menos " . ADMIN_SENHA_MIN_TAMANHO . " caracteres.";
    }
    if (ADMIN_SENHA_MIN_LETRAS > 0 && (int)preg_match_all('/\p{L}/u', $senha) < ADMIN_SENHA_MIN_LETRAS) {
        return "A senha deve ter pelo menos " . ADMIN_SENHA_MIN_LETRAS . " letra(s).";
    }
    if (ADMIN_SENHA_MIN_NUMEROS > 0 && (int)preg_match_all('/[0-9]/', $senha) < ADMIN_SENHA_MIN_NUMEROS) {
        return "A senha deve ter pelo menos " . ADMIN_SENHA_MIN_NUMEROS . " número(s).";
    }
    if (ADMIN_SENHA_MIN_ESPECIAIS > 0 && (int)preg_match_all('/[^\p{L}0-9]/u', $senha) < ADMIN_SENHA_MIN_ESPECIAIS) {
        return "A senha deve ter pelo menos " . ADMIN_SENHA_MIN_ESPECIAIS . " caractere(s) especial(is).";
    }
    return null;
}

/** Versão sem segredos, pra expor ao navegador do painel admin. */
function admin_config_publica(): array {
    return [
        'senhaMinTamanho' => ADMIN_SENHA_MIN_TAMANHO,
        'senhaMinLetras' => ADMIN_SENHA_MIN_LETRAS,
        'senhaMinNumeros' => ADMIN_SENHA_MIN_NUMEROS,
        'senhaMinEspeciais' => ADMIN_SENHA_MIN_ESPECIAIS,
        'tokenTamanho' => ADMIN_TOKEN_TAMANHO,
        'lembrarMeDisponivel' => ADMIN_LEMBRAR_ME_DISPONIVEL,
        // Vem de config_compartilhada.php (não é uma config do painel em
        // si) — exposto aqui pra o navegador do painel nunca precisar
        // buscar o config_publica.php do site público só por causa deste
        // único valor (um fetch a mais por página, por uma coisa só).
        'exigirAprovacaoContaAluno' => EXIGIR_APROVACAO_CONTA,
        'exigirAprovacaoTrocaTurma' => EXIGIR_APROVACAO_TROCA_TURMA,
    ];
}
