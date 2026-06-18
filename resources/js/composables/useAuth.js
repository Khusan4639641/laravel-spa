import { readonly, ref } from 'vue';
import http, { csrf } from '../api/http';

const user = ref(null);
const loading = ref(false);
const initialized = ref(false);

export function useAuth() {
    async function fetchUser() {
        loading.value = true;

        try {
            const { data } = await http.get('/api/me');
            user.value = data.data;
        } catch {
            user.value = null;
        } finally {
            initialized.value = true;
            loading.value = false;
        }

        return user.value;
    }

    async function login(credentials) {
        loading.value = true;

        try {
            await csrf();
            const { data } = await http.post('/api/login', credentials);
            user.value = data.data;
            initialized.value = true;

            return user.value;
        } finally {
            loading.value = false;
        }
    }

    async function logout() {
        loading.value = true;

        try {
            await http.post('/api/logout');
        } finally {
            user.value = null;
            initialized.value = true;
            loading.value = false;
        }
    }

    return {
        user: readonly(user),
        loading: readonly(loading),
        initialized: readonly(initialized),
        fetchUser,
        login,
        logout,
    };
}
