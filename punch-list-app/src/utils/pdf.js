import {
  formatAssignee,
  formatDueDateShort,
  formatStatusLabel,
  formatTitle,
  sanitizeBuilding,
  sanitizeRoom,
  sanitizeSection,
  sanitizeSeverity,
  toSlug,
} from './sanitize.js';

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
        <td>${sanitizeBuilding(task.building)}</td>
        <td>${sanitizeRoom(task.room)}</td>
        <td>${formatTitle(task.title)}</td>
        <td>${sanitizeSection(task.section)}</td>
        <td>${sanitizeSeverity(task.severity)}</td>
        <td>${formatStatusLabel(task.status)}</td>
        <td>${formatAssignee(task.assignee)}</td>
        <td>${formatDueDateShort(task.dueDate)}</td>
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
    formatTitle(task.title),
    `${sanitizeSection(task.section)}`,
    sanitizeSeverity(task.severity),
    formatStatusLabel(task.status),
    formatAssignee(task.assignee),
    formatDueDateShort(task.dueDate),
  ]);
}

export async function exportRoomTasks(building, room, tasks) {
  const buildingValue = sanitizeBuilding(building);
  const roomValue = sanitizeRoom(room);
  const buildingTitle = buildingValue === 'Unassigned' ? 'Unassigned Building' : `Building ${buildingValue}`;
  const roomTitle = `Room ${roomValue}`;
  const title = `${buildingTitle} - ${roomTitle}`;
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
    const buildingSlug = toSlug(buildingValue, 'unassigned');
    const roomSlug = toSlug(roomValue, 'room');
    doc.save(`punch-list-${buildingSlug}-${roomSlug}.pdf`);
  } catch (error) {
    fallbackHtml(title, tasks);
  }
}

export async function exportBuildingTasks(building, tasks) {
  const buildingValue = sanitizeBuilding(building);
  const title = buildingValue === 'Unassigned' ? 'Unassigned Building' : `Building ${buildingValue}`;
  const filtered = tasks.filter((task) => sanitizeBuilding(task.building) === buildingValue);
  if (filtered.length === 0) return;
  try {
    const jsPDF = await ensurePdfLibs();
    const doc = new jsPDF();
    doc.setFontSize(16);
    doc.text(`Punch List: ${title}`, 14, 18);
    const grouped = filtered.reduce((acc, task) => {
      const key = `Room ${sanitizeRoom(task.room)}`;
      if (!acc[key]) acc[key] = [];
      acc[key].push(task);
      return acc;
    }, {});
    let y = 24;
    Object.entries(grouped).forEach(([roomLabel, roomTasks], index) => {
      if (index > 0) {
        const last = doc.lastAutoTable;
        y = last ? last.finalY + 8 : y + 8;
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
    const buildingSlug = toSlug(buildingValue, 'unassigned');
    doc.save(`punch-list-building-${buildingSlug}.pdf`);
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
      const building = sanitizeBuilding(task.building);
      const room = sanitizeRoom(task.room);
      const key = `${building}::${room}`;
      if (!acc[key]) acc[key] = { building, room, tasks: [] };
      acc[key].tasks.push(task);
      return acc;
    }, {});
    let y = 24;
    Object.values(grouped)
      .sort((a, b) => {
        if (a.building === b.building) return a.room.localeCompare(b.room, undefined, { numeric: true, sensitivity: 'base' });
        return a.building.localeCompare(b.building, undefined, { numeric: true, sensitivity: 'base' });
      })
      .forEach((group, index) => {
        if (index > 0) {
          const last = doc.lastAutoTable;
          y = last ? last.finalY + 8 : y + 8;
          if (y > doc.internal.pageSize.height - 40) {
            doc.addPage();
            y = 24;
          }
        }
        const buildingLabel = group.building === 'Unassigned' ? 'Unassigned Building' : `Building ${group.building}`;
        doc.setFontSize(13);
        doc.text(`${buildingLabel} • Room ${group.room}`, 14, y);
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
