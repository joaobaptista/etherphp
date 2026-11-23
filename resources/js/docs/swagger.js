// Swagger UI for the API docs (/docs/api). Bundled locally by Vite: no CDN at runtime.
import '@fontsource-variable/inter';
import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle.js';
import SwaggerUIStandalonePreset from 'swagger-ui-dist/swagger-ui-standalone-preset.js';
import 'swagger-ui-dist/swagger-ui.css';
import './swagger.css';

const AUTH_TOKEN_KEY = 'ethernity_auth_token';

function readJson(id) {
    const node = document.getElementById(id);
    return node ? JSON.parse(node.textContent || 'null') : null;
}

function currentAppToken() {
    try {
        return window.localStorage.getItem(AUTH_TOKEN_KEY) || '';
    } catch {
        return '';
    }
}

const spec = readJson('openapi-spec');

const ui = SwaggerUIBundle({
    spec,
    dom_id: '#swagger-ui',
    deepLinking: true,
    persistAuthorization: true,
    displayRequestDuration: true,
    filter: true,
    docExpansion: 'list',
    defaultModelsExpandDepth: 0,
    tryItOutEnabled: false,
    presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
    plugins: [SwaggerUIBundle.plugins.DownloadUrl],
    layout: 'BaseLayout',
    onComplete: () => {
        // Logged into the app in this browser? Use that session for "Try it out".
        const token = currentAppToken();
        const schemes = Object.keys(spec?.components?.securitySchemes ?? {});
        if (token !== '' && schemes.length > 0) {
            ui.preauthorizeApiKey(schemes[0], token);
        }
    },
});

window.ui = ui;
