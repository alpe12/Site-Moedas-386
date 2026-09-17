<?php
declare(strict_types=1);
require __DIR__ . '/api/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

$data = request_json();
$acao = (string)($data['acao'] ?? '');

if ($acao === 'login') {
    $email = normalize_email((string)($data['email'] ?? ''));
    $senha = (string)($data['senha'] ?? '');
    $lembrarMe = ADMIN_LEMBRAR_ME_DISPONIVEL && !empty($data['lembrarMe']);

    if ($email === '' || $senha === '') {
        json_response(['sucesso' => false, 'mensagem' => 'E-mail e senha são obrigatórios.'], 400);
    }

    admin_enforce_rate_limit('login', $email, 8, 300);

    foreach (csv_assoc(ADMINS_CSV) as $a) {
        if (normalize_email($a['email']) !== $email) continue;
        if (!password_verify($senha, $a['senha_hash'])) break;

        if (!admin_conta_esta_ativa($a)) {
            json_response(['sucesso' => false, 'mensagem' => 'Esta conta ainda está pendente de aprovação.'], 403);
        }

        admin_sessao_iniciar_se_necessario(true);
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['admin_email'] = trim((string)$a['email']);
        $_SESSION['admin_nome'] = (string)$a['nome'];
        $_SESSION['ultima_atividade'] = time();
        $_SESSION['duracao_maxima'] = $lembrarMe ? ADMIN_LOGIN_LEMBRAR_SEGUNDOS : ADMIN_LOGIN_DURACAO_SEGUNDOS;

        json_response([
            'sucesso' => true,
            'email' => $_SESSION['admin_email'],
            'nome' => $_SESSION['admin_nome'],
        ]);
    }

    json_response(['sucesso' => false, 'mensagem' => 'E-mail ou senha incorretos.'], 401);
}

if ($acao === 'cadastro') {
    $nomeRaw = (string)($data['nome'] ?? '');
    $emailRaw = trim((string)($data['email'] ?? ''));
    $senha = (string)($data['senha'] ?? '');
    $token = trim((string)($data['token'] ?? ''));

    $nome = sanitize_plain_text($nomeRaw, 80);
    if ($nome === null) {
        json_response(['sucesso' => false, 'mensagem' => 'Nome inválido.'], 400);
    }

    $email = filter_var(normalize_email($emailRaw), FILTER_VALIDATE_EMAIL);
    if ($email === false) {
        json_response(['sucesso' => false, 'mensagem' => 'E-mail inválido.'], 400);
    }

    if (($erro = validar_senha_admin($senha)) !== null) {
        json_response(['sucesso' => false, 'mensagem' => $erro], 400);
    }

    if ($token === '') {
        json_response(['sucesso' => false, 'mensagem' => 'Token de administração obrigatório.'], 400);
    }

    // Limite por IP (não há "muitas contas legítimas atrás do mesmo IP"
    // aqui como no cadastro de aluno — criar conta admin é raro).
    admin_enforce_rate_limit('cadastro', client_ip(), 10, 3600);

    if (!usar_token_mestre($token)) {
        json_response(['sucesso' => false, 'mensagem' => 'Token de administração inválido ou já utilizado.'], 403);
    }

    // A partir daqui o token já foi "gasto" (rotacionado) — mesmo que o
    // cadastro falhe abaixo por outro motivo (e-mail duplicado, por
    // exemplo), um novo token já é necessário para tentar de novo. Isso é
    // intencional: o token é de uso único por definição.

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $ativo = EXIGIR_APROVACAO_ADMIN ? '0' : '1';

    $resultado = with_locked_csv(ADMINS_CSV, function (array $admins) use ($email, $nome, $hash, $ativo) {
        foreach ($admins as $a) {
            if (normalize_email($a['email']) === $email) {
                return ['return' => ['erro' => 'email_duplicado']];
            }
        }
        $admins[] = ['email' => $email, 'nome' => $nome, 'senha_hash' => $hash, 'ativo' => $ativo, 'criado_em' => date('c')];
        return ['rows' => $admins, 'return' => ['ok' => true]];
    }, ADMIN_CSV_HEADERS['admins']);

    if (!is_array($resultado) || !empty($resultado['erro'])) {
        $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
        $mensagens = [
            'email_duplicado' => 'Esse e-mail já está cadastrado como administrador.',
            'bloqueado' => 'O sistema está ocupado no momento. Tente novamente em alguns segundos.',
            'arquivo_indisponivel' => 'Não foi possível acessar os dados agora. Tente novamente em instantes.',
        ];
        json_response(['sucesso' => false, 'mensagem' => $mensagens[$erro] ?? 'Não foi possível salvar o cadastro.'], 409);
    }

    registrar_log_admin($email, 'cadastro_admin', "Nova conta admin criada: $nome <$email>");

    json_response([
        'sucesso' => true,
        'mensagem' => 'Conta administrativa criada com sucesso.',
        'contaAtiva' => !EXIGIR_APROVACAO_ADMIN,
    ]);
}

if ($acao === 'redefinirSenha') {
    $email = normalize_email((string)($data['email'] ?? ''));
    $token = trim((string)($data['token'] ?? ''));
    $novaSenha = (string)($data['novaSenha'] ?? '');

    if ($email === '' || $token === '' || $novaSenha === '') {
        json_response(['sucesso' => false, 'mensagem' => 'Preencha todos os campos.'], 400);
    }
    if (($erro = validar_senha_admin($novaSenha)) !== null) {
        json_response(['sucesso' => false, 'mensagem' => $erro], 400);
    }

    admin_enforce_rate_limit('redefinirSenha', $email, 5, 3600);

    if (!usar_token_mestre($token)) {
        json_response(['sucesso' => false, 'mensagem' => 'Token de administração inválido ou já utilizado.'], 403);
    }

    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $resultado = with_locked_csv(ADMINS_CSV, function (array $admins) use ($email, $novoHash) {
        $encontrado = false;
        foreach ($admins as &$a) {
            if (normalize_email($a['email']) === $email) {
                $a['senha_hash'] = $novoHash;
                $encontrado = true;
            }
        }
        unset($a);
        if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
        return ['rows' => $admins, 'return' => ['ok' => true]];
    }, ADMIN_CSV_HEADERS['admins']);

    if (!is_array($resultado) || !empty($resultado['erro'])) {
        // O token já foi gasto mesmo que o e-mail não exista — de propósito,
        // pra não dar pra alguém descobrir quais e-mails são admin só testando.
        json_response(['sucesso' => false, 'mensagem' => 'Não foi possível redefinir a senha.'], 400);
    }

    registrar_log_admin($email, 'redefinicao_senha', 'Senha redefinida via token mestre.');

    json_response(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso!']);
}

json_response(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
