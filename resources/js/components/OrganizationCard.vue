<script setup>
import { computed } from 'vue';
import { ExternalLink } from '@lucide/vue';
const props = defineProps({ organization: { type: Object, default: null }, storedCount: { type: Number, default: 0 } });
const updatedAt = computed(() => props.organization?.last_successful_sync_at
    ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(props.organization.last_successful_sync_at)) : '—');
</script>
<template>
    <section class="stats-panel" aria-label="Данные организации">
        <div class="section-heading">
            <h2>{{ organization?.title || (organization ? 'Данные ещё не получены' : 'Организация не выбрана') }}</h2>
            <a v-if="organization?.normalized_url" class="icon-link" :href="organization.normalized_url" target="_blank" rel="noopener noreferrer" title="Открыть Яндекс.Карты">
                <ExternalLink :size="18" aria-hidden="true" />
            </a>
        </div>
        <dl class="stats-grid">
            <div><dt>Рейтинг</dt><dd>{{ organization?.rating ?? '—' }}</dd></div>
            <div><dt>Оценок на Яндексе</dt><dd>{{ organization?.title ? organization.ratings_count : '—' }}</dd></div>
            <div><dt>Отзывов на Яндексе</dt><dd>{{ organization?.title ? organization.reviews_count : '—' }}</dd></div>
            <div><dt>Последнее успешное обновление</dt><dd class="stats-date">{{ updatedAt }}</dd></div>
        </dl>
        <p class="muted">Отзывов в сохранённой истории: {{ storedCount }}</p>
        <p v-if="organization?.last_successful_sync_at && organization?.status !== 'ready'" class="muted">Показаны данные последней успешной синхронизации.</p>
    </section>
</template>
