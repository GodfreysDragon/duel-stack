import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Reverb speaks the Pusher protocol, so Echo drives it through pusher-js.
 * Private/presence subscriptions are authorised by POST /broadcasting/auth
 * with the session cookie and the CSRF token from <meta>.
 */
export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

/**
 * Echo reads the CSRF token once, at startup. pusher-js keeps a reference to
 * the same headers object, so updating it in place covers later logins.
 */
export function setEchoCsrfToken(token) {
    echo.connector.options.auth.headers['X-CSRF-TOKEN'] = token;
}

export const connection = echo.connector.pusher.connection;
