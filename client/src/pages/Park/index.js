import './index.scss';
import { computed, ref, onMounted } from '@vue/composition-api';
import requester from '@/globals/requester';
import useI18n from '@/hooks/vue/useI18n';
import useRouter from '@/hooks/vue/useRouter';
import Help from '@/components/Help';
import Page from '@/components/Page';
import ParkForm from './Form';
import ParkTotals from './Totals';

const WIP_STORAGE_KEY = 'WIP-newPark';

// @vue/component
const ParkEditPage = (props, { root }) => {
    const __ = useI18n();
    const { route, router } = useRouter();
    const id = computed(() => route.value.params.id || null);
    const isNew = computed(() => !id.value || id.value === 'new');

    // TODO: Refactoriser ça + Utiliser des ref() séparées.
    const state = ref({
        help: 'page-parks.help-edit',
        error: null,
        isLoading: false,
        isFetched: false,
        park: {
            id: id.value,
            name: '',
            street: '',
            postal_code: '',
            locality: '',
            country_id: '',
            total_amount: 0,
            note: '',
        },
        errors: {
            name: null,
            street: null,
            postal_code: null,
            locality: null,
            country_id: null,
        },
    });

    const flushStashedData = () => {
        localStorage.removeItem(WIP_STORAGE_KEY);
    };

    const resetHelpLoading = () => {
        state.value.help = 'page-parks.help-edit';
        state.value.error = null;
        state.value.isLoading = true;
    };

    const displayError = (error) => {
        state.value.help = 'page-parks.help-edit';
        state.value.error = error;

        // @ts-ignore TODO: Utiliser un typage correct pour la gestion des erreurs de validation de l'API
        const { code, details } = error.response?.data?.error || { code: 0, details: {} };
        if (code === 400) {
            state.value.errors = { ...details };
        }
    };

    const getParkData = async () => {
        if (isNew.value) {
            const stashedData = localStorage.getItem(WIP_STORAGE_KEY);
            if (stashedData) {
                state.value.park = JSON.parse(stashedData);
            }
            state.value.isFetched = true;
            return;
        }

        resetHelpLoading();

        try {
            const { data } = await requester.get(`parks/${id.value}`);
            state.value.park = data;
            state.value.isFetched = true;
        } catch (error) {
            displayError(error);
        } finally {
            state.value.isLoading = false;
        }
    };

    const save = async (parkData) => {
        resetHelpLoading();

        const request = isNew.value ? requester.post : requester.put;
        const endpoint = isNew.value ? 'parks' : `parks/${id.value}`;

        try {
            const { data } = await request(endpoint, parkData);
            state.value.park = data;
            state.value.help = { type: 'success', text: 'page-parks.saved' };
            flushStashedData();
            root.$store.dispatch('parks/refresh');
            setTimeout(() => { router.push('/parks'); }, 300);
        } catch (error) {
            displayError(error);
        } finally {
            state.value.isLoading = false;
        }
    };

    const handleChange = (newData) => {
        if (!isNew.value) {
            return;
        }

        const stashedData = JSON.stringify(newData);
        localStorage.setItem(WIP_STORAGE_KEY, stashedData);
    };

    const handleCancel = () => {
        flushStashedData();
        router.back();
    };

    onMounted(() => {
        getParkData();
    });

    const pageTitle = isNew
        ? __('page-parks.add')
        : __('page-parks.edit', { pageSubTitle: state.value.park.name });

    return () => {
        const {
            isFetched,
            park,
            errors,
            help,
            error,
            isLoading,
        } = state.value;

        return (
            <Page name="park-edit" title={pageTitle}>
                {isFetched && (
                    <ParkForm
                        park={park}
                        errors={errors}
                        onSubmit={save}
                        onChange={handleChange}
                        onCancel={handleCancel}
                    />
                )}
                <div class="Park__sidebar">
                    <Help message={help} error={error} isLoading={isLoading} />
                    {isFetched && !isNew.value && <ParkTotals park={park} />}
                </div>
            </Page>
        );
    };
};

export default ParkEditPage;