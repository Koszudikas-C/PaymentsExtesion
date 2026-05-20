# Guia de Integração - Endpoint `/verify`

Este documento explica como utilizar o endpoint `/verify` para validar o status da licença de um usuário em uma extensão do Google Chrome.

---

## 🛠️ Especificação do Endpoint

O endpoint `/verify` aceita requisições tanto por **GET** quanto por **POST**. Ele valida se o perfil do usuário possui uma licença ativa e válida.

* **URL:** `http://<seu-dominio>/verify`
* **Métodos:** `GET` | `POST`
* **CORS:** Ativado para todas as origens (`*`).

### Parâmetros da Requisição

Os parâmetros podem ser enviados na Query String (para `GET`) ou no corpo da requisição formatado como JSON (para `POST`).

| Parâmetro | Tipo | Descrição |
| :--- | :--- | :--- |
| `chrome_identity_id` | `string` | O identificador único do usuário (geralmente o e-mail ou o ID do perfil obtido via `chrome.identity`). Permite apenas letras, números, `@`, `.`, `-` e `_`. |
| `extension_id` | `string` | O ID da extensão do Chrome (geralmente obtido via `chrome.runtime.id`). Deve conter apenas letras minúsculas (a-z). |

---

## 💻 Exemplos de Requisição

### 1. Usando JavaScript/TypeScript (Chrome Extension)

Nas extensões do Chrome, a validação geralmente ocorre no **background script** (Service Worker) ou em algum script de inicialização. Veja como implementar usando a API `fetch`:

```typescript
interface LicenseVerificationResponse {
  status: 'active' | 'inactive' | 'not_found' | 'error';
  message: string;
  plan?: string;
  expiresAt?: string;
}

/**
 * Verifica o status da licença do usuário atual no servidor de pagamentos.
 * @param userEmail O e-mail do usuário obtido (ex: via chrome.identity ou prompt do usuário)
 */
async function verifyUserLicense(userEmail: string): Promise<LicenseVerificationResponse> {
  const serverUrl = 'http://localhost:8000/verify'; // Altere para a URL de produção do seu servidor
  const extensionId = chrome.runtime.id; // Obtém automaticamente o ID da extensão atual

  try {
    const response = await fetch(serverUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        chrome_identity_id: userEmail,
        extension_id: extensionId
      })
    });

    if (!response.ok) {
      // Trata erros HTTP (ex: 400, 403, 500)
      const errorData = await response.json();
      throw new Error(errorData.message || `Erro do servidor: ${response.status}`);
    }

    const data: LicenseVerificationResponse = await response.json();
    return data;
  } catch (error) {
    console.error('Falha ao verificar licença:', error);
    return {
      status: 'error',
      message: error instanceof Error ? error.message : 'Erro desconhecido ao conectar ao servidor.'
    };
  }
}

// Exemplo de uso:
// verifyUserLicense('usuario@exemplo.com').then((res) => {
//   if (res.status === 'active') {
//     console.log(`Licença ativa! Plano: ${res.plan}. Expira em: ${res.expiresAt}`);
//     // Habilita as funcionalidades premium
//   } else {
//     console.warn(`Licença inválida (${res.status}): ${res.message}`);
//     // Redireciona para a página de checkout/compra
//   }
// });
```

---

### 2. Usando cURL (GET)

Substitua os valores de teste pelos dados reais:

```bash
curl -X GET "http://localhost:8000/verify?chrome_identity_id=usuario@exemplo.com&extension_id=abcdefghijklmnopqrstuvwxyzabcdef"
```

---

### 3. Usando cURL (POST com JSON)

```bash
curl -X POST "http://localhost:8000/verify" \
     -H "Content-Type: application/json" \
     -d '{"chrome_identity_id": "usuario@exemplo.com", "extension_id": "abcdefghijklmnopqrstuvwxyzabcdef"}'
```

---

## 📦 Estruturas de Retorno (JSON)

### 1. Licença Ativa e Válida (`status: active`)
Retornado quando o usuário possui um pagamento confirmado (`RECEIVED`) e o período da licença ainda não expirou.
* **HTTP Status:** `200 OK`

```json
{
  "status": "active",
  "message": "Licença ativa e válida.",
  "plan": "AIFreelas - Assinatura Mensal",
  "expiresAt": "2026-06-20 12:00:00"
}
```

### 2. Licença Inativa/Expirada (`status: inactive`)
Retornado quando o usuário já foi cadastrado mas não possui um pagamento recente ativo ou a licença expirou.
* **HTTP Status:** `200 OK`

```json
{
  "status": "inactive",
  "message": "A licença vinculada a este perfil está inativa ou expirada."
}
```

### 3. Licença Não Encontrada (`status: not_found`)
Retornado se não houver registros desse e-mail/Chrome ID no banco de dados.
* **HTTP Status:** `200 OK`

```json
{
  "status": "not_found",
  "message": "Nenhuma licença ativa vinculada a este perfil."
}
```

### 4. Parâmetros Obrigatórios Ausentes (`status: error`)
Retornado quando `chrome_identity_id` ou `extension_id` não são enviados.
* **HTTP Status:** `400 Bad Request`

```json
{
  "status": "error",
  "message": "Parâmetros chrome_identity_id e extension_id são obrigatórios."
}
```

### 5. Extensão Não Autorizada (`status: error`)
Retornado se a variável de ambiente `CHROME_EXTENSION_ID` estiver definida no `.env` e a requisição vier com um `extension_id` diferente.
* **HTTP Status:** `403 Forbidden`

```json
{
  "status": "error",
  "message": "Acesso não autorizado para esta extensão."
}
```
