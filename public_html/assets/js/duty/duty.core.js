const DUTY_API = BASE_URL + 'controllers/duty.php';

const Duty = {
  role: document.getElementById('duty-app')?.dataset.role,
  async api(action, data = {}) {
    const res = await fetch(`${DUTY_API}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });

    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      console.error('Duty API không phải JSON:', text);
      throw new Error('Invalid JSON');
    }
  }
};
