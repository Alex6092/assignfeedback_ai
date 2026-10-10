#!/usr/bin/env node
// Serveur MCP « moodle » (stdio) : relie Claude Code ou Codex à un site Moodle
// équipé du plugin local_aibridge. Configuration par variables
// d'environnement (voir README.md). Rien n'est écrit sur stdout en dehors du
// protocole MCP : les traces vont sur stderr.

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { ConfigError, loadConfig } from './config.js';
import { MoodleClient } from './moodle-client.js';
import { registerTools } from './tools.js';

const log = (message) => process.stderr.write(`[moodle-mcp] ${message}\n`);

async function main() {
  let config;
  try {
    config = loadConfig();
  } catch (error) {
    if (error instanceof ConfigError) {
      log(`Configuration : ${error.message}`);
      process.exit(1);
    }
    throw error;
  }

  const client = new MoodleClient(config);
  const write = config.readonly ? '' : ' Écriture (bac à sable du pont seulement, tout est créé masqué) : '
    + 'moodle_create_course, moodle_upsert_section, moodle_create_module, puis moodle_get_module pour vérifier ; '
    + 'moodle_delete_draft retire un brouillon encore masqué. Toujours un dry_run avant une création.';
  const server = new McpServer(
    { name: 'moodle', version: '0.2.0' },
    {
      instructions: 'Accès à Moodle via le plugin local_aibridge. Commencer par moodle_site_info, puis '
        + 'moodle_list_courses et moodle_get_course pour le plan d\'un cours, moodle_get_module pour le détail '
        + 'd\'une activité. Pour comparer un cours entier à des fichiers sources, moodle_snapshot_course écrit '
        + 'tout sur le disque. Aucune donnée d\'élève ni aucun secret n\'est renvoyé.' + write,
    },
  );
  registerTools(server, client, { log, readonly: config.readonly });

  await server.connect(new StdioServerTransport());
  log(`prêt (${config.url}, ${config.readonly ? 'lecture seule' : 'lecture et écriture en bac à sable'})`);
}

main().catch((error) => {
  log(`Erreur fatale : ${error?.stack ?? error}`);
  process.exit(1);
});
