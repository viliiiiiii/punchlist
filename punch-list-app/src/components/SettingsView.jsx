import React, { useRef, useState } from 'react';
import { useTasks } from '../context/TaskContext.jsx';

export default function SettingsView() {
  const { tasks, setTasks, seedDemo, clearTasks, settings, setSettings } = useTasks();
  const [testOutput, setTestOutput] = useState('');
  const fileInputRef = useRef(null);

  const handleExportJson = () => {
    const blob = new Blob([JSON.stringify({ tasks, settings }, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'punch-list-export.json';
    link.click();
    URL.revokeObjectURL(url);
  };

  const handleImportJson = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      try {
        const parsed = JSON.parse(reader.result);
        if (Array.isArray(parsed.tasks)) {
          setTasks(parsed.tasks);
        }
        if (parsed.settings && typeof parsed.settings === 'object') {
          setSettings(parsed.settings);
        }
        setTestOutput('Import successful.');
      } catch (error) {
        console.error(error);
        setTestOutput('Import failed: invalid JSON.');
      }
    };
    reader.readAsText(file);
    event.target.value = '';
  };

  const handleRunTests = () => {
    const issues = [];
    tasks.forEach((task) => {
      if (!task.id) issues.push(`Task missing id: ${task.title}`);
      if (!task.title) issues.push(`Task missing title: ${task.id}`);
      if (!['open', 'in_progress', 'done'].includes(task.status)) {
        issues.push(`Task ${task.id} has invalid status ${task.status}`);
      }
    });
    if (issues.length === 0) {
      setTestOutput(`All ${tasks.length} tasks look good!`);
    } else {
      setTestOutput(issues.join('\n'));
    }
  };

  return (
    <div className="panel">
      <section className="settings-section">
        <h3>Storage</h3>
        <p>Optionally configure a presign endpoint. Leave blank to keep images as local Data URLs.</p>
        <input
          type="text"
          value={settings.presignEndpoint}
          onChange={(event) => setSettings({ presignEndpoint: event.target.value })}
          placeholder="https://example.com/presign"
        />
      </section>

      <section className="settings-section">
        <h3>Data Import / Export</h3>
        <div className="button-row">
          <button className="primary" onClick={handleExportJson}>Export JSON</button>
          <button className="ghost" onClick={() => fileInputRef.current?.click()}>Import JSON</button>
          <input ref={fileInputRef} type="file" accept="application/json" onChange={handleImportJson} hidden />
        </div>
      </section>

      <section className="settings-section">
        <h3>Demo & Tests</h3>
        <div className="button-row">
          <button className="ghost" onClick={seedDemo}>Seed Demo Data</button>
          <button className="ghost" onClick={clearTasks}>Clear Tasks</button>
          <button className="ghost" onClick={handleRunTests}>Run Tests</button>
        </div>
        {testOutput && <pre className="test-output">{testOutput}</pre>}
      </section>
    </div>
  );
}
