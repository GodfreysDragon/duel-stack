import { reactive } from 'vue';
import { api, setCsrfToken } from './api';

/** The signed-in player, shared by every view. */
export const session = reactive({
    ready: false,
    user: null,
    activeMatchId: null,
});

function apply(data) {
    session.user = data.user;
    session.activeMatchId = data.active_match_id;
    if (data.csrf_token) setCsrfToken(data.csrf_token);
    session.ready = true;
    return data;
}

export const refreshSession = async () => apply(await api('GET', '/api/me'));
export const register = async (name, password) => apply(await api('POST', '/api/register', { name, password }));
export const login = async (name, password) => apply(await api('POST', '/api/login', { name, password }));
export const logout = async () => apply(await api('POST', '/api/logout'));
