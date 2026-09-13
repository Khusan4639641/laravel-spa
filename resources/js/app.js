import './bootstrap';

import { createApp } from 'vue';
import App from './App.vue';
import router from './router';

// Expired cookies on any protected request return the SPA to its login route.
import http from './api/http';
import { useAuth } from './composables/useAuth';
http.interceptors.response.use(response => response, error => {
    if (error.response?.status === 401 && error.config?.url !== '/api/login') {
        useAuth().clearUser();
        if (router.currentRoute.value.path !== '/login') router.replace('/login');
    }
    return Promise.reject(error);
});

createApp(App)
    .use(router)
    .mount('#app');
