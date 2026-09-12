# Ferramentas do projecto

## Graphify

Graphify `0.9.55` foi instalado e a skill oficial foi copiada para `.agents/skills/graphify`. O primeiro mapeamento foi executado em modo oficial `--code-only`, pois o ambiente não possui chave LLM para extracção semântica de documentos.

Resultado final desta etapa: 324 nós, 306 relações e 52 comunidades em `graphify-out/`. Dependências, builds, uploads e secrets são excluídos pelo `.gitignore`.

Pendência: configurar futuramente um backend LLM aprovado e reconstruir o grafo para incluir a documentação. O aviso de uma skill Claude global antiga pertence ao perfil local e não afecta a skill versionada neste projecto.

## Humanizer

A skill `blader/humanizer` foi instalada localmente em `.agents/skills/humanizer`. A instalação reportou que o instalador `skills@1.5.23` requer Node `>=22.20.0`, embora tenha concluído com Node 20.16.0. O conteúdo instalado pode ser usado, mas futuras actualizações pelo CLI requerem Node compatível.

Humanizer aplica-se apenas a conteúdo textual destinado a pessoas. Nunca deve alterar código, SQL, JSON, números, contratos, rubricas oficiais, textos legais, nomes institucionais ou regras de negócio.
