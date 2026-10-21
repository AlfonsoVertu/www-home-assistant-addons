# 📚 WP GPT Automation Pro – Memory Manager

## Cos’è la “Memoria” e a cosa serve

La **memoria** di WP GPT Automation Pro è un sistema avanzato per la gestione di “obiettivi” (goals) e “step” (azioni o sotto-task), pensato per conservare lo storico delle azioni, progressi e strategie di automazione **direttamente nel database di WordPress**, in tabelle dedicate e protette.

Questa classe permette di:
- **Tracciare flussi e processi** divisi in obiettivi e step (stile workflow manager)
- **CRUD avanzato**: creare, leggere, modificare, eliminare goals e steps
- **Chunking** e salvataggio di risposte lunghe via transient
- **Controllo permessi** per ogni operazione (basato su `user_code`)
- **Pulizia, log, e sicurezza** su ogni operazione (anche in debug.log)
- **API RESTful** per interagire da frontend, automazioni o altri plugin

---

## **1. Architettura: Tabelle e Struttura Dati**

Memory_Manager crea e gestisce **2 tabelle custom**:

### Tabella `gpt_memory_goals`
| Campo        | Tipo       | Descrizione                  |
|--------------|------------|------------------------------|
| id           | BIGINT     | Primary key, autoincrement   |
| goal_title   | VARCHAR255 | Titolo obiettivo             |
| goal_type    | VARCHAR60  | Tipologia (es: default, ecc) |
| keyword      | VARCHAR100 | Keyword associata            |
| user_code    | VARCHAR120 | Proprietario/utente          |
| status       | VARCHAR40  | Stato (in_progress, deleted) |
| meta         | LONGTEXT   | Metadati serializzati        |
| created_at   | DATETIME   | Timestamp creazione          |
| updated_at   | DATETIME   | Timestamp modifica           |

### Tabella `gpt_memory_steps`
| Campo        | Tipo       | Descrizione                  |
|--------------|------------|------------------------------|
| id           | BIGINT     | Primary key, autoincrement   |
| goal_id      | BIGINT     | Relazione con goals          |
| step_type    | VARCHAR60  | Tipologia step               |
| step_label   | VARCHAR255 | Nome o descrizione step      |
| status       | VARCHAR40  | Stato (pending, complete…)   |
| meta         | LONGTEXT   | Metadati serializzati        |
| created_at   | DATETIME   | Timestamp creazione          |
| updated_at   | DATETIME   | Timestamp modifica           |

---

## **2. Setup, Autocheck e Creazione Tabelle**

### Setup automatico

- La **classe controlla automaticamente ogni 2 ore** l’esistenza e la struttura delle tabelle tramite `maybe_create_tables`.
- Se non esistono, vengono create via `dbDelta` (compatibile con upgrade e modifiche future).
- In caso di errore, scrive dettagli su `error_log` (solo se WP_DEBUG attivo).

---

## **3. Permessi e Sicurezza**

Ogni record è protetto da un **user_code**.  
Solo chi possiede il `user_code` corretto può modificare o cancellare un proprio obiettivo o step.

Ogni operazione sensibile è loggata (se WP_DEBUG attivo).

---

## **4. API RESTful: tutte le azioni**

La classe espone una rotta unica:

```
POST /wp-gpt/v2/memory/goal
```

Il body deve contenere almeno il campo `action`  
(es: `create_goal`, `add_step`, ecc.) e gli altri parametri richiesti.

**Azioni disponibili**:

- `create_goal` – crea nuovo goal
- `get_goal` – ottieni dettagli goal (permesso user_code)
- `update_goal` – modifica goal (permesso user_code)
- `delete_goal` – soft delete (status=deleted)
- `hard_delete_goal` – cancella dal db (permesso user_code)
- `list_goals` – elenca tutti i goals (con filtri: user_code, status, limit, offset)
- `delete_all_goals` – elimina tutti i goals per user_code (soft/hard)
- `add_step` – aggiungi step ad un goal (permesso user_code)
- `get_step` – ottieni uno step
- `update_step` – aggiorna step (permesso user_code)
- `delete_step` – soft delete di uno step
- `hard_delete_step` – elimina fisicamente step
- `list_steps` – lista tutti gli step di un goal (o tutti)
- `delete_all_steps` – elimina tutti gli step di un goal/user_code (soft/hard)
- `hard_delete_steps_by_goal` – elimina TUTTI gli step di uno specifico goal
- `get_status` – riepilogo percentuale di completamento di un goal
- `maybe_chunked_response` – risposta chunked per dati molto lunghi
- `get_chunked_response` – recupera un chunk specifico
- `delete_chunk` – cancella un chunk/transient
- `maybe_create_tables` – forza il controllo/creazione tabelle

---

## **5. Come usare la memoria: Esempi pratici**

### **A. Creare un obiettivo**

```json
{
  "action": "create_goal",
  "goal_title": "Scrivere articolo SEO",
  "goal_type": "writing",
  "keyword": "seo avanzata",
  "user_code": "TUO_USER_CODE"
}
```

### **B. Aggiungere step ad un goal**

```json
{
  "action": "add_step",
  "goal_id": 1,
  "step_type": "paragraph",
  "step_label": "Scrivi introduzione",
  "user_code": "TUO_USER_CODE"
}
```

### **C. Ottenere stato avanzamento**

```json
{
  "action": "get_status",
  "goal_id": 1,
  "user_code": "TUO_USER_CODE"
}
```

Risposta esempio:
```json
{
  "success": true,
  "goal_id": 1,
  "total_steps": 5,
  "completed_steps": 3,
  "pending_steps": 1,
  "failed_steps": 1,
  "deleted_steps": 0,
  "percent_completed": 60
}
```

---

## **6. Chunking e gestione risposte lunghe**

Per grandi quantità di dati, Memory_Manager **spezzetta i dati** (max 10.000 caratteri/chunk) e li salva come transient.  
Puoi recuperare i chunk uno alla volta:

### **A. Creare una risposta chunked**
```json
{
  "action": "maybe_chunked_response",
  "data": {...} // oggetto molto grande
}
```
Risposta:
```json
{
  "success": true,
  "chunked": true,
  "chunk_key": "wp_gpt_memory_chunk_abc123",
  "total_chunks": 3
}
```

### **B. Recuperare chunk specifico**
```json
{
  "action": "get_chunked_response",
  "chunk_key": "wp_gpt_memory_chunk_abc123",
  "chunk_index": 0
}
```

### **C. Eliminare un chunk**
```json
{
  "action": "delete_chunk",
  "chunk_key": "wp_gpt_memory_chunk_abc123"
}
```

---

## **7. Best Practice d’uso**

- **Usa un `user_code` univoco** per ogni sessione/utente per sicurezza e multitenancy.
- Salva i meta come array: Memory_Manager li serializza automaticamente.
- Recupera step e goals con `limit` e `offset` per gestire grossi volumi di dati.
- Usa `status` per distinguere oggetti attivi, completati o cancellati (soft delete).
- Verifica sempre la risposta di ogni azione (`success` true/false, messaggi errore).

---

## **8. Error Handling & Debug**

- Tutte le operazioni fallite restituiscono una struttura `success: false` e un messaggio di errore chiaro.
- Se WP_DEBUG è attivo, log dettagliato in `/wp-content/debug.log` (operazioni, errori DB, permessi negati).

---

## **9. Nota tecnica: estendibilità**

La classe è progettata per essere **estensibile**:  
- Puoi aggiungere nuovi campi alle tabelle (compatibile con `dbDelta`)
- Puoi estendere le azioni REST aggiungendo nuovi case nel router unico
- Il chunking è pensato per dati di qualsiasi dimensione, non solo testi ma anche output JSON complessi

---

## **10. Esempio completo di ciclo di vita**

1. **Creazione goal** (`create_goal`)
2. **Aggiunta step** (`add_step`)
3. **Aggiornamento stato step** (`update_step`)
4. **Controllo avanzamento** (`get_status`)
5. **Soft/hard delete** (`delete_goal`, `hard_delete_goal`)

---

## **11. Sicurezza e multiutenza**

- **Solo chi ha il `user_code` giusto** può modificare o cancellare i propri dati.
- La logica del router impedisce azioni non autorizzate anche via REST.

---

## **12. Integrazione con il proxy MCP e automazioni SSH**

Sebbene il Memory Manager gestisca dati applicativi, spesso i workflow includono passaggi operativi remoti. Per questo il plugin espone due endpoint complementari:

- `POST /wp-json/wp-gpt/v1/manage-mcp-server`: orchestri il deploy (`deploy_script`), l'installazione dipendenze (`install_dependencies`), la sincronizzazione certificati (`sync_certificates`), l'avvio (`start`), l'arresto (`stop`), il riavvio (`restart`) e il polling (`status`) del proxy `tools/mcp_ws_proxy.py`. Ogni risposta 202 restituisce `job_id`, `job_log_file` e `tls_remote_paths`, consultabili successivamente per verificare l'esito.
- `POST /wp-json/wp-gpt/v1/ssh-connection`: esegui comandi puntuali (`ssh-connection`) e ottieni l'output chunked tramite `get_ssh_connection_single_response_in_chunk` o la pulizia con `delete_ssh_connection_transients`. È utile per validare automaticamente deploy/script all'interno di un workflow memorizzato.

Entrambi gli endpoint richiedono `Authorization: Bearer <TOKEN>` e `user_code`. I job asincroni (deploy/start/sync/install) sono persistiti in `remote_jobs`/`dependency_jobs` e possono essere richiamati dal tuo workflow tramite l'azione `status` per aggiornare la memoria con lo stato dell'infrastruttura.

---

> **Memory_Manager** è la base per workflow, automazioni, sequenze, task writer, cronologia avanzata o gestione “memoria contestuale” in plugin, chatbot, sistemi editoriali complessi e molto altro.

---

**WP GPT Automation Pro – Documentazione memoria, flussi, sicurezza e API v2**  
_Aggiornato al 2025_

---

Se vuoi una versione con più esempi **JSON**, spiegazioni d’uso avanzate, FAQ o hai bisogno di casi d’uso particolari… dimmelo!

