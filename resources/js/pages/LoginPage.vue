<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { LogIn } from '@lucide/vue';
import ErrorMessage from '../components/ErrorMessage.vue';
import { useAuth } from '../composables/useAuth';

const router = useRouter();
const { login } = useAuth();

const form = reactive({
    email: '',
    password: '',
});
const loading = ref(false);
const message = ref('');
const errors = ref({});

async function submit() {
    loading.value = true;
    message.value = '';
    errors.value = {};

    try {
        await login(form);
        await router.push('/settings');
    } catch (error) {
        message.value = error.response?.data?.message || 'Не удалось войти.';
        errors.value = error.response?.data?.errors || {};
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <main class="auth-layout">
        <section class="auth-panel" aria-labelledby="login-title">
            <div class="brand-mark">Я</div>
            <h1 id="login-title">Yandex Maps Reviews</h1>
            <p class="auth-panel__subtitle">Вход для администратора</p>

            <ErrorMessage :message="message" :errors="errors" />

            <form class="form-stack" @submit.prevent="submit">
                <label class="field">
                    <span>Email</span>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        :disabled="loading"
                    >
                </label>

                <label class="field">
                    <span>Password</span>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :disabled="loading"
                    >
                </label>

                <button class="button button--primary" type="submit" :disabled="loading">
                    <LogIn :size="18" aria-hidden="true" />
                    <span>{{ loading ? 'Вход...' : 'Войти' }}</span>
                </button>
            </form>
        </section>
    </main>
</template>
