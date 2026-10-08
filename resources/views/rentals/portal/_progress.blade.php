{{-- One progress line for a fault (fault AND work order): one row per step, the date each reached step was reached, nothing else.
     $p is the Alpine expression holding {steps:[{key,label,state,current,at,detail,action,work_order_id}]}. --}}
<div class="steps" data-progress>
    <template x-for="st in {{ $p }}.steps" :key="st.key">
        <div class="step" :class="{ done: st.state === 'done', current: st.current }" :data-step="st.key">
            <span class="dot"></span>
            <span x-text="st.label"></span>
            <span class="when" x-show="st.at" x-text="fmtDay(st.at)"></span>
            <span class="detail" x-show="st.detail" x-text="st.detail"></span>
            <button class="btn btn-primary" x-show="st.action === 'check'" @click="tenantTab='jobs'; loadWorkOrders()">Check it now</button>
        </div>
    </template>
</div>
