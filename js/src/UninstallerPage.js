export default function createUninstallerPage(app, ExtensionPage, m) {
  const plain = value => Array.isArray(value) ? value.map(plain).join('') : (value == null ? '' : String(value));
  const t = (key, args = {}) => plain(app.translator.trans('el-choco-extension-uninstaller.admin.' + key, args));
  const uid = () => Array.from(window.crypto.getRandomValues(new Uint8Array(16))).map(x => x.toString(16).padStart(2, '0')).join('');
  const confirmationWord = plan => plan.confirmationWord || (plan.mode === 'disable' ? 'DISABLE' : 'DELETE');
  const label = command => { try { return JSON.parse(command).package || t('execution'); } catch (_) { return command; } };

  return class UninstallerPage extends ExtensionPage {
    oninit(vnode) {
      super.oninit(vnode);
      this.data = {extensions: [], history: []}; this.filter = ''; this.selected = null;
      this.mode = 'keep'; this.plan = null; this.job = null; this.confirmation = '';
      this.purgeConfirmed = false; this.error = ''; this.busy = false; this.disposed = false;
      this.load(true);
    }

    endpoint() { return app.forum.attribute('apiUrl') + '/extension-uninstaller'; }

    async load(resume = false) {
      try {
        this.data = await app.request({method: 'GET', url: this.endpoint()});
        if (resume) {
          const active = this.data.activeJob || this.data.history[0]?.id;
          if (active) this.watch(active);
        }
      } catch (error) { this.error = this.message(error); }
      if (!this.disposed) m.redraw();
    }

    message(error) { return error.response?.message || error.response?.errors?.[0]?.detail || error.message || t('request_error'); }

    async submit(payload) {
      if (this.busy) return;
      this.busy = true; this.error = ''; this.plan = null;
      const requestId = uid();
      this.pending = {...payload, requestId};
      try {
        const job = await app.request({method: 'POST', url: this.endpoint(), body: this.pending});
        this.pending = null;
        this.watch(job.id);
      } catch (error) { this.error = this.message(error); this.busy = false; }
      if (!this.disposed) m.redraw();
    }

    async retry() {
      if (!this.pending || this.busy) return;
      this.busy = true; this.error = '';
      try {
        const job = await app.request({method: 'POST', url: this.endpoint(), body: this.pending});
        this.pending = null; this.watch(job.id);
      } catch (error) { this.error = this.message(error); this.busy = false; }
      if (!this.disposed) m.redraw();
    }

    async watch(id) {
      clearTimeout(this.timer);
      try {
        const job = await app.request({method: 'GET', url: this.endpoint() + '?job=' + encodeURIComponent(id)});
        if (this.disposed) return;
        this.job = job;
        if (job.workerSeen) this.data.workerSeen = job.workerSeen;
        this.busy = ['queued', 'running'].includes(job.status);
        if (job.status === 'succeeded' && job.result?.id) {
          this.plan = job.result; this.selected = job.result.extension; this.mode = job.result.mode;
        }
        if (this.busy) this.timer = setTimeout(() => this.watch(id), 750);
        else this.load();
      } catch (error) {
        if (this.disposed) return;
        this.error = this.message(error);
        this.timer = setTimeout(() => this.watch(id), 5000);
      }
      if (!this.disposed) m.redraw();
    }

    onremove(vnode) { this.disposed = true; clearTimeout(this.timer); if (super.onremove) super.onremove(vnode); }

    choose(extension) {
      if (this.busy || this.data.activeJob) return;
      this.selected = extension; this.plan = null; this.confirmation = ''; this.purgeConfirmed = false; this.error = '';
    }

    content() {
      const rows = this.data.extensions || [];
      const selected = this.selected;
      const blocked = this.busy || Boolean(this.data.activeJob) || !selected || selected.protected;
      const workerOnline = this.data.workerSeen && Date.now() / 1000 - this.data.workerSeen < 30;
      const visible = rows.filter(row => [row.title, row.name].some(text => text.toLowerCase().includes(this.filter.toLowerCase())));
      const modes = ['disable', 'keep', 'purge'];
      return m('div.eu', [
        m('header.eu-hero', [m('h2.eu-eyebrow', 'el-choco · ' + t('eyebrow')), m('p', t('subtitle')),
          m('span.eu-connection', {className: workerOnline ? 'is-online' : 'is-offline'}, [m('span.eu-dot'), workerOnline ? t('worker_online') : t('worker_offline')])]),
        !workerOnline && m('div.eu-notice', {role:'status'}, t('worker_help')),
        m('div.eu-metrics', [
          m('div', [m('strong', rows.length), m('span', t('installed'))]),
          m('div', [m('strong', rows.filter(r => r.enabled).length), m('span', t('enabled'))]),
          m('div', [m('strong', this.data.history.filter(j => j.status === 'succeeded').length), m('span', t('completed'))])]),
        this.error && m('div.eu-error', {role:'alert'}, [this.error, this.pending && m('button.Button', {onclick:()=>this.retry(),disabled:this.busy}, t('retry_same'))]),
        m('div.eu-layout', [
          m('section.eu-catalog', [m('div.eu-section-head', [m('h3', t('library')), m('button.Button.Button--icon', {title:t('refresh'),disabled:this.busy,onclick:()=>this.load()}, m('i.fas.fa-sync'))]),
            m('label.eu-search', [m('span', t('search')), m('input.FormControl', {value:this.filter,placeholder:t('search_hint'),oninput:e=>{this.filter=e.target.value;}})]),
            m('div.eu-list', {role:'group', 'aria-label':t('library')}, visible.length ? visible.map(row => m('button.eu-extension', {
              key:row.name, type:'button', disabled:this.busy || Boolean(this.data.activeJob),
              className:selected?.name===row.name?'is-selected':'', 'aria-pressed':selected?.name===row.name, onclick:()=>this.choose(row)
            }, [m('span.eu-package-icon', m('i.fas', {className:row.protected?'fa-shield-alt':'fa-puzzle-piece'})),
              m('span.eu-extension-copy', [m('strong',row.title),m('small',row.name),m('span.eu-version', row.version)]),
              m('span.eu-badge', {className:row.enabled?'is-enabled':''},row.protected?t('protected'):(row.enabled?t('active'):t('inactive')))])) : m('p.eu-empty',t('empty')))
          ]),
          m('section.eu-inspector', selected ? [
            m('div.eu-section-head',m('h3',selected.title)), m('code.eu-package-name',selected.name), m('p.eu-description',selected.description),
            selected.protected ? m('div.eu-notice',t('protected_help')) : [
              selected.dependents.length>0 && m('div.eu-notice',[m('strong',t('dependents')),m('p',selected.dependents.join(', '))]),
              m('div.eu-steps',[m('span.is-current','1 · '+t('choose')),m('span',{className:this.plan?'is-current':''},'2 · '+t('review')),m('span','3 · '+t('confirm'))]),
              m('fieldset.eu-modes',[m('legend',t('action')), ...modes.map(mode=>m('label.eu-mode',{className:this.mode===mode?'is-selected':''},[
                m('input',{type:'radio',name:'eu-mode',value:mode,checked:this.mode===mode,disabled:blocked,onchange:()=>{this.mode=mode;this.plan=null;this.confirmation='';this.purgeConfirmed=false;}}),
                m('span',[m('strong',t('mode_'+mode)),m('small',t('mode_'+mode+'_help'))])]))]),
              m('button.Button.Button--primary.eu-preview',{disabled:blocked || (this.mode!=='disable' && (!selected.direct || selected.dependents.length>0)),onclick:()=>this.submit({action:'preview',package:selected.name,mode:this.mode})},[m('i.fas.fa-search'), ' '+t('preview')]),
              !selected.direct && m('p.eu-muted',t('indirect')),
              this.plan && this.plan.package===selected.name && m('section.eu-plan',[
                m('h4',[m('i.fas.fa-check-circle'),' '+t('plan_ready')]),
                m('p',t('expires',{time:new Date(this.plan.expires*1000).toLocaleTimeString()})),
                this.plan.mode==='disable'?m('p',t('disable_plan')):m('div',[m('strong',t('removed_packages')),m('ul.eu-removed-packages',this.plan.removed.map(row=>m('li',{key:row.name},[m('code',row.name),m('span.eu-plan-version',t('package_version',{version:row.version}))])))]),
                this.plan.mode==='purge' && m('div.eu-danger-note',[m('strong',t('purge_title')),m('p',t('purge_detail')),m('details',[m('summary',t('migrations',{count:this.plan.migrationFiles.length})),m('ul',this.plan.migrationFiles.map(file=>m('li',file)))])]),
                m('label.eu-confirm',[m('span',t('type_confirmation',{word:confirmationWord(this.plan)})),m('input.FormControl',{value:this.confirmation,placeholder:confirmationWord(this.plan),autocomplete:'off',spellcheck:false,oninput:e=>{this.confirmation=e.target.value;}})]),
                this.plan.mode==='purge'&&m('label.eu-purge-check',[m('input',{type:'checkbox',checked:this.purgeConfirmed,onchange:e=>{this.purgeConfirmed=e.target.checked;}}),m('span',t('purge_confirm'))]),
                m('button.Button.eu-execute',{className:this.plan.mode==='purge'?'Button--danger':'Button--primary',disabled:blocked || this.confirmation.trim()!==confirmationWord(this.plan) || (this.plan.mode==='purge'&&!this.purgeConfirmed) || Date.now()/1000>this.plan.expires,
                  onclick:()=>this.submit({action:'execute',plan:this.plan.id,confirmation:this.confirmation,purgeConfirmed:this.purgeConfirmed})},t('execute_'+this.plan.mode))
              ])
            ]
          ] : m('div.eu-welcome',[m('i.fas.fa-box-open'),m('h3',t('welcome')),m('p',t('welcome_help'))]))
        ]),
        this.job && m('section.eu-job',[
          m('div.eu-section-head',[m('h3',t('job')),m('span.eu-job-status', t('status_'+this.job.status)+' · '+Number(this.job.seconds||0).toLocaleString(undefined,{maximumFractionDigits:1})+' s')]),
          m('p.eu-muted',[t('phase')+': '+t('phase_'+(this.job.phase||'preflight')),' · ',m('code',this.job.id)]),
          this.job.backup && m('p',t('backup_id',{id:this.job.backup})),
          this.job.status==='interrupted'&&m('div.eu-error',t('interrupted_help')),
          this.job.hasWarnings&&m('div.eu-notice',t('warnings')),
          m('pre.eu-output',{tabindex:'0','aria-label':t('output')},this.job.output || t('waiting'))
        ]),
        m('section.eu-history',[m('h3',t('history')),this.data.history.length?m('ul',this.data.history.slice(0,8).map(job=>m('li',{key:job.id},[
          m('button',{onclick:()=>this.watch(job.id),disabled:this.busy},[m('span',label(job.command)),m('small',new Date(job.time).toLocaleString()),m('span',t('status_'+job.status))])
        ]))):m('p.eu-muted',t('no_history'))]),
        m('footer.eu-footer', t('footer'))
      ]);
    }
  };
}
