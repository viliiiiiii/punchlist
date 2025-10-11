const JSPDF_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
const AUTOTABLE_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js';

function loadScript(src, id) {
  return new Promise((resolve, reject) => {
    if (typeof window === 'undefined') {
      reject(new Error('No window environment'));
      return;
    }
    if (document.getElementById(id)) {
      resolve();
      return;
    }
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.id = id;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(`Failed to load ${src}`));
    document.body.appendChild(script);
  });
}

async function ensurePdfLibs() {
  try {
    await loadScript(JSPDF_CDN, 'jspdf-cdn');
    await loadScript(AUTOTABLE_CDN, 'jspdf-autotable-cdn');
    if (window.jspdf?.jsPDF) {
      return window.jspdf.jsPDF;
    }
    throw new Error('jsPDF not available on window');
  } catch (error) {
    console.warn('Falling back to HTML export', error);
    throw error;
  }
}

function fallbackHtml(title, tasks) {
  const grouped = tasks
    .map(
      (task) => `
      <tr>
        <td>${task.building}</td>
        <td>${task.room}</td>
        <td>${task.title}</td>
        <td>${task.section}</td>
        <td>${task.severity}</td>
        <td>${task.status}</td>
        <td>${task.assignee || ''}</td>
        <td>${task.dueDate || ''}</td>
      </tr>`
    )
    .join('');
  const html = `
    <html>
      <head>
        <title>${title}</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 24px; }
          table { border-collapse: collapse; width: 100%; }
          th, td { border: 1px solid #ccc; padding: 8px; font-size: 12px; }
          th { background: #f4f4f4; }
        </style>
      </head>
      <body>
        <h1>${title}</h1>
        <table>
          <thead>
            <tr>
              <th>Building</th>
              <th>Room</th>
              <th>Title</th>
              <th>Section</th>
              <th>Severity</th>
              <th>Status</th>
              <th>Assignee</th>
              <th>Due</th>
            </tr>
          </thead>
          <tbody>${grouped}</tbody>
        </table>
        <script>window.print();</script>
      </body>
    </html>`;
  const win = window.open('', '_blank');
  if (win) {
    win.document.write(html);
    win.document.close();
  } else {
    const blob = new Blob([html], { type: 'text/html' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${title}.html`;
    link.click();
    URL.revokeObjectURL(url);
  }
}

function tableBody(tasks) {
  return tasks.map((task) => [
    task.title,
    `${task.section}`,
    task.severity,
    task.status.replace('_', ' '),
    task.assignee || '',
    task.dueDate || '',
  ]);
}

export async function exportRoomTasks(building, room, tasks) {
  const title = `Building ${building} - Room ${room}`;
  try {
    const jsPDF = await ensurePdfLibs();
    const doc = new jsPDF();
    doc.setFontSize(16);
    doc.text(`Punch List: ${title}`, 14, 18);
    doc.autoTable({
      startY: 26,
      head: [['Title', 'Section', 'Severity', 'Status', 'Assignee', 'Due']],
      body: tableBody(tasks),
      styles: { fontSize: 10 },
    });
    doc.save(`punch-list-${building}-${room}.pdf`);
  } catch (error) {
    fallbackHtml(title, tasks);
  }
}

export async function exportBuildingTasks(building, tasks) {
  const filtered = tasks.filter((task) => task.building === building);
  const title = `Building ${building}`;
  if (filtered.length === 0) return;
  try {
    const jsPDF = await ensurePdfLibs();
    const doc = new jsPDF();
    doc.setFontSize(16);
    doc.text(`Punch List: ${title}`, 14, 18);
    const grouped = filtered.reduce((acc, task) => {
      const key = `Room ${task.room}`;
      if (!acc[key]) acc[key] = [];
      acc[key].push(task);
      return acc;
    }, {});
    let y = 24;
    Object.entries(grouped).forEach(([roomLabel, roomTasks], index) => {
      if (index > 0) {
        y = doc.lastAutoTable.finalY + 8;
      }
      doc.setFontSize(13);
      doc.text(roomLabel, 14, y);
      doc.autoTable({
        startY: y + 4,
        head: [['Title', 'Section', 'Severity', 'Status', 'Assignee', 'Due']],
        body: tableBody(roomTasks),
        styles: { fontSize: 10 },
      });
    });
    doc.save(`punch-list-building-${building}.pdf`);
  } catch (error) {
    fallbackHtml(title, filtered);
  }
}

export async function exportAllTasks(tasks) {
  if (!tasks || tasks.length === 0) return;
  const title = 'All Tasks';
  try {
    const jsPDF = await ensurePdfLibs();
    const doc = new jsPDF({ orientation: 'landscape' });
    doc.setFontSize(16);
    doc.text('Punch List: All Buildings', 14, 18);
    const grouped = tasks.reduce((acc, task) => {
      const key = `${task.building}-${task.room}`;
      if (!acc[key]) acc[key] = { building: task.building, room: task.room, tasks: [] };
      acc[key].tasks.push(task);
      return acc;
    }, {});
    let y = 24;
    Object.values(grouped)
      .sort((a, b) => {
        if (a.building === b.building) return a.room.localeCompare(b.room, undefined, { numeric: true });
        return a.building.localeCompare(b.building);
      })
      .forEach((group, index) => {
        if (index > 0) {
          y = doc.lastAutoTable.finalY + 8;
          if (y > doc.internal.pageSize.height - 40) {
            doc.addPage();
            y = 24;
          }
        }
        doc.setFontSize(13);
        doc.text(`Building ${group.building} • Room ${group.room}`, 14, y);
        doc.autoTable({
          startY: y + 4,
          head: [['Title', 'Section', 'Severity', 'Status', 'Assignee', 'Due']],
          body: tableBody(group.tasks),
          styles: { fontSize: 9 },
        });
      });
    doc.save('punch-list-all.pdf');
  } catch (error) {
    fallbackHtml(title, tasks);
  }
}
