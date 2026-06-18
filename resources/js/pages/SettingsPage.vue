<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ExternalLink, LogOut, RefreshCw, Save } from '@lucide/vue';
import ErrorMessage from '../components/ErrorMessage.vue';
import LoadingState from '../components/LoadingState.vue';
import Pagination from '../components/Pagination.vue';
import ReviewsList from '../components/ReviewsList.vue';
import http from '../api/http';
import { useAuth } from '../composables/useAuth';

const router = useRouter();
const { user, logout } = useAuth();

const sourceUrl = ref('');
const organization = ref(null);
const reviews = ref([]);
const reviewsMeta = ref({
    current_page: 1,
    per_page: 50,
    total: 0,
    last_page: 1,
});

const initialLoading = ref(false);
const saving = ref(false);
const syncing = ref(false);
const reviewsLoading = ref(false);
const message = ref('');
const errors = ref({});

const hasOrganization = computed(() => Boolean(organization.value));
const parserBusy = computed(() => saving.value || syncing.value);
const statusLabel = computed(() => {
    const status = organization.value?.scrape_status;

    if (status === 'success') {
        return 'Готово';
    }

    if (status === 'processing') {
        return 'Парсинг';
    }

    if (status === 'failed') {
        return 'Ошибка';
    }

    return 'Ожидание';
});
const formattedUpdatedAt = computed(() => {
    if (!organization.value?.last_scraped_at) {
        return '—';
    }

    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(organization.value.last_scraped_at));
});

onMounted(async () => {
    await loadOrganization();
    await loadReviews(1);
});

async function loadOrganization() {
    initialLoading.value = true;

    try {
        const { data } = await http.get('/api/organization');
        organization.value = data.data;
        sourceUrl.value = data.data?.source_url || sourceUrl.value;
    } catch (error) {
        message.value = error.response?.data?.message || 'Не удалось загрузить организацию.';
    } finally {
        initialLoading.value = false;
    }
}

async function loadReviews(page = 1) {
    reviewsLoading.value = true;

    try {
        const { data } = await http.get('/api/organization/reviews', {
            params: {
                page,
                per_page: 50,
            },
        });
        reviews.value = data.data;
        reviewsMeta.value = data.meta;
    } catch (error) {
        message.value = error.response?.data?.message || 'Не удалось загрузить отзывы.';
    } finally {
        reviewsLoading.value = false;
    }
}

async function saveAndSync() {
    saving.value = true;
    clearErrors();

    try {
        const { data } = await http.post('/api/organization', {
            source_url: sourceUrl.value,
        });
        organization.value = data.data;
        await loadReviews(1);
    } catch (error) {
        handleApiError(error, 'Не удалось сохранить ссылку.');
        await loadReviews(1);
    } finally {
        saving.value = false;
    }
}

async function resync() {
    syncing.value = true;
    clearErrors();

    try {
        const { data } = await http.post('/api/organization/sync');
        organization.value = data.data;
        await loadReviews(1);
    } catch (error) {
        handleApiError(error, 'Не удалось обновить данные.');
        await loadReviews(1);
    } finally {
        syncing.value = false;
    }
}

async function handleLogout() {
    await logout();
    await router.push('/login');
}

function handleApiError(error, fallbackMessage) {
    message.value = error.response?.data?.message || fallbackMessage;
    errors.value = error.response?.data?.errors || {};

    if (error.response?.data?.data !== undefined) {
        organization.value = error.response.data.data;
    }
}

function clearErrors() {
    message.value = '';
    errors.value = {};
}
</script>

<template>
    <main class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">Yandex Maps Reviews</p>
                <h1>Настройки организации</h1>
            </div>

            <div class="topbar__actions">
                <span class="user-chip">{{ user?.email }}</span>
                <button class="button button--ghost" type="button" @click="handleLogout">
                    <LogOut :size="18" aria-hidden="true" />
                    <span>Выйти</span>
                </button>
            </div>
        </header>

        <LoadingState v-if="initialLoading" label="Загрузка настроек" />

        <template v-else>
            <ErrorMessage :message="message || organization?.scrape_error" :errors="errors" />

            <section class="settings-grid">
                <div class="sync-panel">
                    <div class="section-heading">
                        <h2>Ссылка Яндекс.Карт</h2>
                        <span class="status-pill" :class="`status-pill--${organization?.scrape_status || 'pending'}`">
                            {{ statusLabel }}
                        </span>
                    </div>

                    <form class="url-form" @submit.prevent="saveAndSync">
                        <label class="field field--wide">
                            <span>URL карточки организации</span>
                            <input
                                v-model="sourceUrl"
                                type="url"
                                placeholder="https://yandex.ru/maps/org/..."
                                :disabled="parserBusy"
                                required
                            >
                        </label>

                        <div class="button-row">
                            <button class="button button--primary" type="submit" :disabled="parserBusy">
                                <Save :size="18" aria-hidden="true" />
                                <span>{{ saving ? 'Синхронизация...' : 'Сохранить' }}</span>
                            </button>

                            <button
                                class="button button--secondary"
                                type="button"
                                :disabled="parserBusy || !hasOrganization"
                                @click="resync"
                            >
                                <RefreshCw :size="18" aria-hidden="true" />
                                <span>{{ syncing ? 'Обновление...' : 'Обновить' }}</span>
                            </button>
                        </div>
                    </form>

                    <LoadingState v-if="parserBusy" label="Парсер загружает данные из Яндекс.Карт" />
                </div>

                <div class="stats-panel">
                    <div class="section-heading">
                        <h2>{{ organization?.title || 'Организация не выбрана' }}</h2>
                        <a
                            v-if="organization?.normalized_url"
                            class="icon-link"
                            :href="organization.normalized_url"
                            target="_blank"
                            rel="noreferrer"
                            title="Открыть источник"
                        >
                            <ExternalLink :size="18" aria-hidden="true" />
                        </a>
                    </div>

                    <dl class="stats-grid">
                        <div>
                            <dt>Рейтинг</dt>
                            <dd>{{ organization?.rating ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt>Оценок</dt>
                            <dd>{{ organization?.ratings_count ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt>Отзывов</dt>
                            <dd>{{ organization?.reviews_count ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt>Обновлено</dt>
                            <dd>{{ formattedUpdatedAt }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <ReviewsList :reviews="reviews" :loading="reviewsLoading" />
            <Pagination :meta="reviewsMeta" :disabled="reviewsLoading" @change="loadReviews" />
        </template>
    </main>
</template>
