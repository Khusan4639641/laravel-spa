<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { LogOut, RefreshCw } from '@lucide/vue';
import ErrorMessage from '../components/ErrorMessage.vue';
import LoadingState from '../components/LoadingState.vue';
import Pagination from '../components/Pagination.vue';
import ReviewsList from '../components/ReviewsList.vue';
import OrganizationCard from '../components/OrganizationCard.vue';
import SyncStatus from '../components/SyncStatus.vue';
import http from '../api/http';
import { useAuth } from '../composables/useAuth';
import { useOrganization } from '../composables/useOrganization';
import { useParsing } from '../composables/useParsing';

const router = useRouter();
const { user, logout } = useAuth();
const { organization, reviews, reviewsMeta, reviewsLoading, loadOrganization, loadReviews } = useOrganization();
const sourceUrl = ref('');
const initialLoading = ref(true);
const submitting = ref(false);
const message = ref('');
const errors = ref({});
const { run, active, pollingError, start, resume } = useParsing(async () => {
    await Promise.all([loadOrganization(), loadReviews(1)]);
});
const busy = computed(() => submitting.value || active.value);

function handleError(error, fallback) {
    message.value = error.response?.status === 419 ? 'Сессия истекла. Обновите страницу и войдите снова.'
        : error.response?.status === 429 ? 'Слишком много запросов. Подождите минуту.'
        : error.response?.data?.message || fallback;
    errors.value = error.response?.data?.errors || {};
}
async function initialize() {
    initialLoading.value = true;
    message.value = '';
    try {
        const data = await loadOrganization();
        sourceUrl.value = data?.source_url || '';
        start(data?.latest_run || null);
        await loadReviews(1);
    } catch (error) { handleError(error, 'Не удалось загрузить настройки.'); }
    finally { initialLoading.value = false; }
}
onMounted(initialize);
async function synchronize(retry = false) {
    submitting.value = true;
    message.value = '';
    errors.value = {};
    try {
        const { data } = retry ? await http.post('/api/organization/sync')
            : await http.post('/api/organization', { source_url: sourceUrl.value });
        // Begin polling immediately; refreshing cached data must not lose the new run ID.
        start({ id: data.data.parsing_run_id, status: data.data.status, progress: 0, reviews_found: 0, reviews_saved: 0 });
        await Promise.all([loadOrganization(), loadReviews(1)]);
    } catch (error) {
        handleError(error, 'Не удалось запустить синхронизацию.');
        if (error.response?.status === 409) {
            const data = await loadOrganization().catch(() => null);
            if (data?.latest_run) start(data.latest_run);
        }
    } finally { submitting.value = false; }
}
async function changePage(page) {
    try { await loadReviews(page); }
    catch (error) { handleError(error, 'Не удалось загрузить отзывы.'); }
}
async function handleLogout() {
    try { await logout(); await router.push('/login'); }
    catch (error) { handleError(error, 'Не удалось выйти.'); }
}
</script>
<template>
    <main class="app-shell">
        <header class="topbar">
            <div><p class="eyebrow">Yandex Maps Reviews</p><h1>Настройки организации</h1></div>
            <div class="topbar__actions">
                <span class="user-chip">{{ user?.email }}</span>
                <button class="button button--ghost" type="button" @click="handleLogout"><LogOut :size="18" aria-hidden="true" /><span>Выйти</span></button>
            </div>
        </header>
        <LoadingState v-if="initialLoading" label="Загрузка настроек" />
        <template v-else>
            <ErrorMessage :message="message" :errors="errors" />
            <button v-if="message && !organization" class="button button--ghost" @click="initialize">Обновить настройки</button>
            <section class="settings-grid">
                <div class="sync-panel">
                    <h2>Ссылка Яндекс.Карт</h2>
                    <form class="url-form" @submit.prevent="synchronize(false)">
                        <label class="field field--wide">
                            <span>URL карточки организации</span>
                            <input v-model="sourceUrl" type="url" placeholder="https://yandex.ru/maps/org/..." :disabled="busy" required maxlength="2048">
                        </label>
                        <button class="button button--primary" type="submit" :disabled="busy">
                            <RefreshCw :size="18" aria-hidden="true" /><span>{{ busy ? 'Синхронизация…' : 'Синхронизировать' }}</span>
                        </button>
                    </form>
                    <SyncStatus :run="run" @retry="synchronize(true)" />
                    <ErrorMessage :message="pollingError" />
                    <button v-if="pollingError && active" type="button" class="button button--secondary" @click="resume">Обновить статус</button>
                </div>
                <OrganizationCard :organization="organization" :stored-count="reviewsMeta.total" />
            </section>
            <ReviewsList :reviews="reviews" :loading="reviewsLoading" />
            <Pagination :meta="reviewsMeta" :disabled="reviewsLoading" @change="changePage" />
        </template>
    </main>
</template>
