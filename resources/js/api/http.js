import axios from 'axios';

const http = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
    },
});

export async function csrf() {
    await http.get('/sanctum/csrf-cookie');
}

export default http;
