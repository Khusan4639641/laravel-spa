<script setup>
import { Star } from '@lucide/vue';
import LoadingState from './LoadingState.vue';

defineProps({
    reviews: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <section class="reviews-section">
        <div class="section-heading">
            <h2>Отзывы</h2>
        </div>

        <LoadingState v-if="loading" label="Загрузка отзывов" />

        <div v-else-if="reviews.length === 0" class="empty-state">
            Отзывов пока нет.
        </div>

        <ul v-else class="reviews-list">
            <li v-for="review in reviews" :key="review.id" class="review-item">
                <div class="review-item__header">
                    <div>
                        <p class="review-item__author">{{ review.author || 'Анонимный пользователь' }}</p>
                        <p class="review-item__date">{{ review.review_date || 'Дата не указана' }}</p>
                    </div>

                    <div v-if="review.rating" class="review-rating" :aria-label="`${review.rating} of 5`">
                        <Star :size="16" aria-hidden="true" />
                        <span>{{ review.rating }}</span>
                    </div>
                </div>

                <p class="review-item__text">{{ review.text || 'Текст отзыва отсутствует.' }}</p>
            </li>
        </ul>
    </section>
</template>
