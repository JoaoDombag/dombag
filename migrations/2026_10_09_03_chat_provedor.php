<?php
// ── 2026-10-09 · Chat com IA — provedor de cada conversa (anthropic | gemini) ─
//  O histórico guardado tem o formato de um provedor; ao trocar de provedor a
//  conversa antiga é só exibida e uma nova é iniciada.
adicionarcampotb('CHAT_CONVERSA', 'CCV_PROVEDOR', "VARCHAR(12) NOT NULL DEFAULT 'anthropic'");
