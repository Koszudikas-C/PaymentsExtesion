# Documentação da API de Pagamentos e Licenças

Esta documentação detalha todos os endpoints disponíveis na API do sistema de pagamentos e licenciamento da Extensão, contendo a finalidade de cada um, os parâmetros de entrada esperados, os códigos de status HTTP e o formato de todas as respostas JSON (incluindo sucessos e falhas).

---

## Sumário
1. [Endpoint de Checkout (`/checkout`)](#1-endpoint-de-checkout-checkout)
2. [Endpoint de Ativação (`/activate`)](#2-endpoint-de-ativação-activate)
3. [Endpoint de Verificação Silenciosa (`/verify`)](#3-endpoint-de-verificação-silenciosa-verify)
4. [Endpoint de Estatísticas de Campanha (`/campaign-stats`)](#4-endpoint-de-estatísticas-de-campanha-campaign-stats)
5. [Endpoint de Health Check (`/health`)](#5-endpoint-de-health-check-health)
6. [Endpoint de Webhook do Asaas (`/webhook`)](#6-endpoint-de-webhook-do-asaas-webhook)

---

## 1. Endpoint de Checkout (`/checkout`)

* **Finalidade**: Ponto de entrada chamado quando o usuário deseja realizar a compra ou assinatura. Se ele já possuir uma licença ativa vinculada ao seu e-mail ou perfil, ele é informado. Caso contrário, gera uma URL de pagamento segura no gateway Asaas populada com os dados do cliente e a retorna para redirecionamento.
* **Rotas**: `/checkout`
* **Métodos**: `GET`, `POST`
* **Headers de CORS**: Suporta requisições `OPTIONS` de preflight (`Access-Control-Allow-Origin: *`).

### Parâmetros de Entrada
Devem ser enviados via parâmetros de URL (`QUERY_STRING`) ou via payload em formato JSON bruto:

| Campo | Tipo | Obrigatoriedade | Descrição |
| :--- | :--- | :--- | :--- |
| `chrome_identity_id` | String | **Obrigatório** | Identificador único do perfil do usuário no Google Chrome. |
| `email` | String | **Obrigatório** | Endereço de e-mail do comprador. |
| `name` | String | Opcional | Nome completo do comprador (padrão: `"Usuário"`). |
| `phone` | String | Opcional | Número de WhatsApp ou telefone celular (padrão: `"unknown"`). |

---

### Respostas (Status Codes e JSON)

#### A. 200 OK - Sucesso (Checkout Pendente)
Retornado quando o cliente é pré-cadastrado com sucesso e a URL de pagamento é gerada.
```json
{
  "status": "pending",
  "checkoutUrl": "https://www.asaas.com/c/hr2uqqei8tgaz8tj?email=cliente%40email.com&name=Nome+Cliente&mobilePhone=5511999999999",
  "message": "Redirecionando para o pagamento seguro no Asaas."
}
```

#### B. 200 OK - Sucesso (Licença Ativa Existente)
Retornado quando o cliente já realizou o pagamento anteriormente e possui uma licença ativa, tornando desnecessária uma nova compra.
```json
{
  "status": "active",
  "plan": "LIFETIME",
  "licenseKey": "17F7-937C-AB8B-66DA",
  "expiresAt": null,
  "message": "Você já possui uma licença ativa! Não é necessário comprar novamente."
}
```

#### C. 400 Bad Request - Parâmetros Ausentes
Retornado se `chrome_identity_id` ou `email` não forem fornecidos.
```json
{
  "status": "error",
  "message": "Parâmetros chrome_identity_id e email são obrigatórios e devem conter dados válidos."
}
```

#### D. 500 Internal Server Error - Falha nas Configurações de Venda
Ocorre se o servidor de backend não possuir a chave de checkout configurada em variáveis de ambiente.
```json
{
  "status": "error",
  "message": "Configurações de pagamento ausentes no servidor."
}
```

#### E. 500 Internal Server Error - Erro Interno
Erro genérico para falhas inesperadas no servidor.
```json
{
  "status": "error",
  "message": "Erro interno de processamento do servidor."
}
```

---

## 2. Endpoint de Ativação (`/activate`)

* **Finalidade**: Chamado ativamente pela interface da extensão para validar e vincular uma chave de licença (`licenseKey`) ao perfil do Chrome atual (`chrome_identity_id`).
* **Rotas**: `/activate`
* **Métodos**: `GET`, `POST`
* **Headers de CORS**: Suporta requisições `OPTIONS` de preflight (`Access-Control-Allow-Origin: *`).

### Parâmetros de Entrada
Devem ser enviados via parâmetros de URL (`QUERY_STRING`) ou via payload em formato JSON bruto:

| Campo | Tipo | Obrigatoriedade | Descrição |
| :--- | :--- | :--- | :--- |
| `chrome_identity_id` | String | **Obrigatório** | Identificador único do perfil do usuário no Google Chrome. |
| `email` | String | **Obrigatório** | E-mail atrelado à compra da licença. |
| `extension_id` | String | **Obrigatório** | Identificador interno da extensão instalada (segurança). |
| `license_key` | String | **Obrigatório** | Chave de licença no formato `AAAA-BBBB-CCCC-DDDD`. |
| `force` | Booleano | Opcional | Se `true`, realiza a transferência forçada da licença para este perfil. |

---

### Respostas (Status Codes e JSON)

#### A. 200 OK - Sucesso (Licença Ativada)
Retornado se a licença for ativa e vinculada com sucesso.
```json
{
  "status": "success",
  "message": "Extensão ativada com sucesso!",
  "licenseKey": "17F7-937C-AB8B-66DA",
  "plan": "MONTHLY",
  "expiresAt": "2026-06-19 12:00:00"
}
```

#### B. 200 OK - Licença ou E-mail Inválido
Retornado se a chave de licença não existir ou o e-mail atrelado não coincidir.
```json
{
  "status": "invalid_key",
  "message": "Chave de licença ou e-mail inválido."
}
```

#### C. 200 OK - Licença Inativa / Sem Pagamento
Retornado se a licença estiver cadastrada mas o pagamento constar como pendente, expirado ou cancelado.
```json
{
  "status": "inactive",
  "message": "A sua licença está inativa ou expirada. Efetue o pagamento para ativar."
}
```

#### D. 200 OK - Conflito de Perfil (Anti-Compartilhamento)
Ocorre quando a licença já está vinculada a outra conta/perfil de Chrome e não foi passado a flag `force=true`.
```json
{
  "status": "conflict",
  "message": "Esta licença já está ativada em outro perfil ou dispositivo do Chrome. Deseja transferir a ativação para este perfil?",
  "can_force": true
}
```

#### E. 400 Bad Request - Parâmetros Ausentes
```json
{
  "status": "error",
  "message": "Parâmetros chrome_identity_id, email, extension_id e license_key são obrigatórios."
}
```

#### F. 403 Forbidden - ID de Extensão Não Autorizado
Ocorre se a extensão que fez a requisição não corresponder ao ID oficial configurado no backend.
```json
{
  "status": "error",
  "message": "Acesso não autorizado para esta extensão."
}
```

#### G. 500 Internal Server Error - Erro do Servidor
```json
{
  "status": "error",
  "message": "Erro interno de processamento do servidor."
}
```

---

## 3. Endpoint de Verificação Silenciosa (`/verify`)

* **Finalidade**: Chamado periodicamente em background pelo service worker da extensão para checar se a ativação da licença continua ativa e válida sem exigir interação do usuário.
* **Rotas**: `/verify`
* **Métodos**: `GET`, `POST`
* **Headers de CORS**: Suporta requisições `OPTIONS` de preflight (`Access-Control-Allow-Origin: *`).

### Parâmetros de Entrada
Devem ser enviados via parâmetros de URL (`QUERY_STRING`) ou via payload em formato JSON bruto:

| Campo | Tipo | Obrigatoriedade | Descrição |
| :--- | :--- | :--- | :--- |
| `chrome_identity_id` | String | **Obrigatório** | Identificador único do perfil do usuário no Google Chrome. |
| `extension_id` | String | **Obrigatório** | Identificador da extensão. |
| `email` | String | Opcional | E-mail do cliente (usado como fallback para vincular perfis órfãos). |

---

### Respostas (Status Codes e JSON)

#### A. 200 OK - Licença Ativa e Válida
```json
{
  "status": "active",
  "message": "Licença ativa e válida.",
  "plan": "LIFETIME",
  "expiresAt": null
}
```

#### B. 200 OK - Licença Inativa ou Expirada
```json
{
  "status": "inactive",
  "message": "A licença vinculada a este perfil está inativa ou expirada."
}
```

#### C. 200 OK - Nenhuma Licença Vinculada ao Perfil
```json
{
  "status": "not_found",
  "message": "Nenhuma licença ativa vinculada a este perfil."
}
```

#### D. 200 OK - Conflito de Perfil/Dispositivo
Ocorre se o e-mail do cliente foi fornecido como fallback, mas a licença já estiver travada em outro `chrome_identity_id` (anti-compartilhamento).
```json
{
  "status": "conflict",
  "message": "Sua licença está ativada em outro perfil ou dispositivo. Faça a ativação novamente neste perfil para transferir o uso."
}
```

#### E. 400 Bad Request - Parâmetros Ausentes
```json
{
  "status": "error",
  "message": "Parâmetros chrome_identity_id e extension_id são obrigatórios."
}
```

#### F. 403 Forbidden - Extensão Não Autorizada
```json
{
  "status": "error",
  "message": "Acesso não autorizado para esta extensão."
}
```

#### G. 500 Internal Server Error - Erro do Servidor
```json
{
  "status": "error",
  "message": "Erro interno de processamento do servidor."
}
```

---

## 4. Endpoint de Estatísticas de Campanha (`/campaign-stats`)

* **Finalidade**: Retorna as métricas da campanha pública atual de vendas, contendo o número total de licenças vendidas (LIFETIME) e o percentual alcançado rumo à meta final.
* **Rotas**: `/campaign-stats`
* **Métodos**: `GET`
* **Headers de CORS**: Suporta requisições `OPTIONS` de preflight.

### Parâmetros de Entrada
*Este endpoint não recebe parâmetros de entrada.*

---

### Respostas (Status Codes e JSON)

#### A. 200 OK - Sucesso
Retorna os dados quantitativos e percentuais.
```json
{
  "status": "success",
  "data": {
    "count": 34,
    "target": 100,
    "percentage": 34.0
  }
}
```

#### B. 500 Internal Server Error - Meta Não Configurada
Ocorre caso o administrador do backend não tenha configurado um alvo (`target`) válido nas variáveis de ambiente.
```json
{
  "status": "error",
  "message": "Campaign target configuration error"
}
```

#### C. 500 Internal Server Error - Erro Genérico
```json
{
  "status": "error",
  "message": "Internal server error"
}
```

---

## 5. Endpoint de Health Check (`/health`)

* **Finalidade**: Simples ponto para ferramentas externas e balanceadores monitorarem se o servidor de backend está online e respondendo a requisições HTTP normais.
* **Rotas**: `/health`
* **Métodos**: `GET`

### Parâmetros de Entrada
*Este endpoint não recebe parâmetros de entrada.*

---

### Respostas (Status Codes e JSON)

#### A. 200 OK - Saudável
```json
{
  "status": "ok"
}
```

---

## 6. Endpoint de Webhook do Asaas (`/webhook`)

* **Finalidade**: Receber chamadas assíncronas do Asaas notificando eventos ocorridos no gateway, como a confirmação de recebimento de pagamentos (`PAYMENT_RECEIVED`).
* **Rotas**: `/webhook`
* **Métodos**: `POST`

### Headers Obrigatórios
Para garantir que a requisição é legítima e provém do Asaas, o header de segurança é estritamente exigido:

| Header | Tipo | Descrição |
| :--- | :--- | :--- |
| `asaas-access-token` | String | Token de webhook gerado no Asaas (deve bater com `ASAAS_TOKEN` no backend). |

---

### Parâmetros de Entrada (Payload JSON)
Recebe a estrutura de webhook JSON padrão do Asaas. O evento chave para ativação automática de licenças é o `PAYMENT_RECEIVED`.

---

### Respostas (Status Codes e JSON)

#### A. 200 OK - Sucesso no Recebimento
Retornado ao Asaas para sinalizar que o evento foi capturado.
```json
{
  "status": "success"
}
```

#### B. 401 Unauthorized - Token de Segurança Inválido
Ocorre se o token no header `asaas-access-token` estiver ausente, expirado ou incorreto.
```json
{
  "status": "error",
  "message": "Unauthorized"
}
```

#### C. 400 Bad Request - Payload Vazio
```json
{
  "status": "error",
  "message": "Empty Payload"
}
```

#### D. 500 Internal Server Error - Erro no Processamento
Ocorre em falhas críticas internas de banco ou processamento de lógica durante a captura do evento.
```json
{
  "status": "error",
  "message": "Internal Server Error"
}
```
