import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatCard from '@/Components/Analytics/StatCard.vue';

describe('StatCard', () => {
  it('AN-V21: renders label and value', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'Mensajes', value: '1,234' },
    });

    expect(wrapper.text()).toContain('Mensajes');
    expect(wrapper.text()).toContain('1,234');
  });

  it('AN-V22: renders optional subtitle', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'Conversaciones', value: '42', subtitle: '10 abiertas' },
    });

    expect(wrapper.text()).toContain('10 abiertas');
  });

  it('AN-V22b: no subtitle when omitted', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'Leads', value: '5' },
    });

    expect(wrapper.findAll('p')).toHaveLength(2);
  });
});
