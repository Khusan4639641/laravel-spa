import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from '../composables/useAuth';
import LoginPage from '../pages/LoginPage.vue';
import SettingsPage from '../pages/SettingsPage.vue';

const routes = [
    {
        path: '/',
        name: 'home',
        component: SettingsPage,
        meta: { requiresAuth: true },
    },
    {
        path: '/login',
        name: 'login',
        component: LoginPage,
        meta: { guestOnly: true },
    },
    {
        path: '/settings',
        name: 'settings',
        component: SettingsPage,
        meta: { requiresAuth: true },
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuth();

    if (!auth.initialized.value) {
        await auth.fetchUser();
    }

    if (to.path === '/') {
        return auth.user.value ? '/settings' : '/login';
    }

    if (to.meta.requiresAuth && !auth.user.value) {
        return '/login';
    }

    if (to.meta.guestOnly && auth.user.value) {
        return '/settings';
    }

    return true;
});

export default router;
