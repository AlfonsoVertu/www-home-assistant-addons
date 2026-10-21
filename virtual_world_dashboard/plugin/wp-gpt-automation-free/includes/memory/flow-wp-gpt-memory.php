<?php
// Flusso RAG (Memory Manager)
//
// 1. create_goal: Crea un nuovo goal e restituisce ID.
// 2. add_step: Aggiunge step (azioni/info) a un goal.
// 3. update_step: Aggiorna stato/result step.
// 4. get_goal: Recupera goal e info.
// 5. query_goals: Ricerca goal per user_code/keyword.
// 6. list_steps: Elenca step di un goal.
// 7. get_status: Sintesi avanzamento goal/step.
// 8. delete_goal: Marca goal e step come deleted.
// 9. delete_step: Marca uno step come deleted.
// 10. maybe_chunked_response: Chunking output se troppo lungo.
// 11. maybe_create_tables: Crea/aggiorna tabelle su plugin load.
//
// Ogni metodo ha un file dedicato!
