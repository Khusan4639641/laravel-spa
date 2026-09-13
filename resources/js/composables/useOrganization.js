import { ref } from 'vue';
import http from '../api/http';

export function useOrganization() {
    const organization = ref(null);
    const reviews = ref([]);
    const reviewsMeta = ref({ current_page: 1, per_page: 50, total: 0, last_page: 1 });
    const reviewsLoading = ref(false);
    let requestId = 0;
    async function loadOrganization() {
        const { data } = await http.get('/api/organization');
        organization.value = data.data;
        return data.data;
    }
    async function loadReviews(page = 1) {
        const id = ++requestId;
        reviewsLoading.value = true;
        try {
            const { data } = await http.get('/api/organization/reviews', { params: { page, per_page: 50 } });
            if (id === requestId) {
                reviews.value = data.data;
                reviewsMeta.value = data.meta;
            }
        } finally {
            if (id === requestId) reviewsLoading.value = false;
        }
    }
    return { organization, reviews, reviewsMeta, reviewsLoading, loadOrganization, loadReviews };
}
