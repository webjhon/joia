/* Markdown used by JOIA replies. All reply content enters the DOM as text. */
function appendJoiaInline(parent, text, allowEmphasis = true){
  const pattern = /`([^`\n]+)`|\*\*([^*\n]+)\*\*|\*([^*\n]+)\*/g;
  let offset = 0;
  for(const match of text.matchAll(pattern)){
    parent.append(text.slice(offset, match.index));
    if(match[1]){
      const code = document.createElement("code");
      code.textContent = match[1];
      parent.append(code);
    } else if(allowEmphasis){
      const emphasis = document.createElement(match[2] ? "strong" : "em");
      appendJoiaInline(emphasis, match[2] ?? match[3], false);
      parent.append(emphasis);
    } else {
      parent.append(match[0]);
    }
    offset = match.index + match[0].length;
  }
  parent.append(text.slice(offset));
}

function renderJoiaMarkdown(text){
  const content = document.createElement("div");
  content.className = "joia-markdown";
  const lines = text.replace(/\r\n?/g, "\n").split("\n");
  const listPattern = /^\s*(?:(\d{1,9})[.)]|[-+*])\s+(.+)$/;
  const headingPattern = /^#{1,6}\s+(.+)$/;
  const fencePattern = /^\s*```[\w+-]*\s*$/;
  const cells = line => line.trim().replace(/^\||\|$/g, "").split("|").map(cell => cell.trim());
  const tableStart = index => {
    const line = lines[index];
    const separator = lines[index + 1];
    return !!separator && line.includes("|") && cells(line).length === cells(separator).length
      && cells(separator).every(cell => /^:?-{3,}:?$/.test(cell));
  };

  for(let index = 0; index < lines.length;){
    const line = lines[index];
    if(!line.trim()){
      index++;
      continue;
    }
    if(fencePattern.test(line)){
      const start = ++index;
      while(index < lines.length && !/^\s*```\s*$/.test(lines[index])) index++;
      const block = document.createElement("pre");
      const code = document.createElement("code");
      code.textContent = lines.slice(start, index).join("\n");
      block.append(code);
      content.append(block);
      if(index < lines.length) index++;
      continue;
    }
    const heading = line.match(headingPattern);
    if(heading){
      const title = document.createElement("h3");
      appendJoiaInline(title, heading[1]);
      content.append(title);
      index++;
      continue;
    }
    const item = line.match(listPattern);
    if(item){
      const ordered = !!item[1];
      const list = document.createElement(ordered ? "ol" : "ul");
      if(ordered) list.start = Number(item[1]);
      while(index < lines.length){
        const entry = lines[index].match(listPattern);
        if(!entry || !!entry[1] !== ordered) break;
        const row = document.createElement("li");
        appendJoiaInline(row, entry[2]);
        list.append(row);
        index++;
        let next = index;
        while(next < lines.length && !lines[next].trim()) next++;
        const nextItem = lines[next]?.match(listPattern);
        if(nextItem && !!nextItem[1] === ordered) index = next;
      }
      content.append(list);
      continue;
    }
    if(tableStart(index)){
      const table = document.createElement("table");
      const header = table.createTHead().insertRow();
      const headings = cells(line);
      for(const name of headings){
        const cell = document.createElement("th");
        cell.scope = "col";
        appendJoiaInline(cell, name);
        header.append(cell);
      }
      const body = table.createTBody();
      index += 2;
      while(index < lines.length && lines[index].includes("|")){
        const fields = cells(lines[index]);
        if(fields.length !== headings.length) break;
        const row = body.insertRow();
        for(const field of fields) appendJoiaInline(row.insertCell(), field);
        index++;
      }
      const wrapper = document.createElement("div");
      wrapper.className = "joia-table";
      wrapper.append(table);
      content.append(wrapper);
      continue;
    }
    const paragraph = document.createElement("p");
    appendJoiaInline(paragraph, line);
    index++;
    while(index < lines.length && lines[index].trim() && !headingPattern.test(lines[index])
      && !listPattern.test(lines[index]) && !fencePattern.test(lines[index]) && !tableStart(index)){
      paragraph.append(document.createElement("br"));
      appendJoiaInline(paragraph, lines[index++]);
    }
    content.append(paragraph);
  }
  return content;
}
