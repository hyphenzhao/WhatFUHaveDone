const MoodNote = {
    date: '', mood: '', loading: false, dirty: false, saveTimer: null,

    init() {
        this.root = document.getElementById('moodNote');
        if (!this.root) return;
        this.content = document.getElementById('moodNoteContent');
        this.status = document.getElementById('moodNoteStatus');
        this.dateLabel = document.getElementById('moodNoteDate');
        this.count = document.getElementById('moodNoteCount');
        this.saveButton = document.getElementById('moodNoteSave');
        this.moodButtons = [...document.querySelectorAll('#moodPicker button')];

        this.content.addEventListener('input', () => {
            this.dirty = true;
            this.updateCount();
            this.setStatus('等待自动保存…');
            this.scheduleSave();
        });
        this.moodButtons.forEach(button => button.addEventListener('click', () => {
            this.mood = this.mood === button.dataset.mood ? '' : button.dataset.mood;
            this.dirty = true;
            this.renderMood();
            this.scheduleSave(300);
        }));
        this.saveButton.addEventListener('click', () => this.save());
        document.addEventListener('app:datechange', event => this.changeDate(event.detail.date));
        this.changeDate(App.selectedDate);
    },

    async changeDate(date) {
        if (!date || date === this.date) return;
        if (this.dirty) await this.save();
        clearTimeout(this.saveTimer);
        this.date = date;
        await this.load();
    },

    async load() {
        this.loading = true;
        this.content.disabled = true;
        this.setStatus('正在读取…');
        this.dateLabel.textContent = formatDate(this.date);
        try {
            const response = await API.moodNotes.get(this.date);
            this.mood = response.data?.mood || '';
            this.content.value = response.data?.content || '';
            this.dirty = false;
            this.renderMood();
            this.updateCount();
            this.setStatus(response.data?.updated_at ? '已保存' : '今天还没有记录');
        } catch (error) {
            this.setStatus(`读取失败：${error.message}`, true);
        } finally {
            this.loading = false;
            this.content.disabled = false;
        }
    },

    scheduleSave(delay = 1000) {
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.save(), delay);
    },

    async save() {
        clearTimeout(this.saveTimer);
        if (this.loading || !this.date || !this.dirty) return;
        const dateBeingSaved = this.date;
        const contentBeingSaved = this.content.value;
        const moodBeingSaved = this.mood;
        this.saveButton.disabled = true;
        this.setStatus('正在保存…');
        try {
            await API.moodNotes.save(dateBeingSaved, moodBeingSaved, contentBeingSaved);
            if (this.date === dateBeingSaved &&
                this.content.value === contentBeingSaved &&
                this.mood === moodBeingSaved) {
                this.dirty = false;
                this.setStatus('已自动保存');
            }
        } catch (error) {
            this.setStatus(`保存失败：${error.message}`, true);
        } finally {
            this.saveButton.disabled = false;
        }
    },

    renderMood() {
        this.moodButtons.forEach(button => {
            const selected = button.dataset.mood === this.mood;
            button.classList.toggle('selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
    },
    updateCount() { this.count.textContent = `${this.content.value.length} / 5000`; },
    setStatus(text, isError = false) {
        this.status.textContent = text;
        this.status.classList.toggle('error', isError);
    },
};

document.addEventListener('DOMContentLoaded', () => MoodNote.init());
