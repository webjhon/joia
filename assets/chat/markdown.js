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

const joiaCodeLanguages = [
  {name: "JavaScript", grammar: "javascript", aliases: ["javascript", "js", "mjs", "cjs", "node", "nodejs", "node.js", "node-js"]},
  {name: "TypeScript", grammar: "typescript", aliases: ["typescript", "ts"]},
  {name: "JSX", grammar: "jsx", aliases: ["jsx", "react", "react-native"]},
  {name: "TSX", grammar: "tsx", aliases: ["tsx"]},
  {name: "CSS", grammar: "css", aliases: ["css"]},
  {name: "HTML", grammar: "markup", aliases: ["html", "xml", "svg", "markup"]},
  {name: "Java", grammar: "java", aliases: ["java"]},
  {name: "JSON", grammar: "json", aliases: ["json", "webmanifest"]},
  {name: "Terminal", grammar: "bash", aliases: ["bash", "sh", "shell", "shellscript", "zsh"]},
  {name: "Python", grammar: "python", aliases: ["python", "py"]},
  {name: "SQL", grammar: "sql", aliases: ["sql"]}
];

function appendJoiaToken(parent, token){
  if(typeof token === "string"){
    parent.append(token);
  } else if(Array.isArray(token)){
    for(const child of token) appendJoiaToken(parent, child);
  } else {
    const span = document.createElement("span");
    span.className = `token ${token.type}`;
    appendJoiaToken(span, token.content);
    parent.append(span);
  }
}

function renderJoiaCodeBox(source, hint, highlight){
  const attribute = source.match(/^(\s*)(className)(\s*=\s*)("[^"\r\n]*"|'[^'\r\n]*')(\s*)$/);
  const inferred = attribute || /^\s*<(?:[A-Z][\w.]*|Animated\.View)(?=\s|\/>)/.test(source) ? "jsx" : "";
  const language = joiaCodeLanguages.find(entry => entry.aliases.includes(hint.toLowerCase() || inferred));
  const figure = document.createElement("figure");
  figure.className = "joia-code-block";
  const caption = document.createElement("figcaption");
  caption.textContent = language?.name ?? (hint || "Código");
  const pre = document.createElement("pre");
  pre.tabIndex = 0;
  const code = document.createElement("code");
  code.textContent = source;

  if(highlight && attribute && (language?.grammar === "jsx" || language?.grammar === "tsx")){
    const [, before, name, operator, value, after] = attribute;
    const annotated = document.createDocumentFragment();
    annotated.append(before);
    appendJoiaToken(annotated, [
      {type: "attr-name", content: name},
      {type: "operator", content: operator},
      {type: "attr-value", content: value}
    ]);
    annotated.append(after);
    code.replaceChildren(annotated);
  } else if(highlight){
    const prism = window.Prism;
    const grammar = language && prism?.languages?.[language.grammar];
    if(grammar && source.length <= 12000){
      try {
        const annotated = document.createDocumentFragment();
        appendJoiaToken(annotated, prism.tokenize(source, grammar));
        code.replaceChildren(annotated);
      } catch {
        // Keep the source readable when a highlighter cannot tokenize it.
      }
    }
  }

  pre.append(code);
  figure.append(caption, pre);
  return figure;
}

function renderJoiaMarkdown(text, {highlight = true} = {}){
  const content = document.createElement("div");
  content.className = "joia-markdown";
  const lines = text.replace(/\r\n?/g, "\n").split("\n");
  const listPattern = /^\s*(?:(\d{1,9})[.)]|[-+*])\s+(.+)$/;
  const headingPattern = /^#{1,6}\s+(.+)$/;
  const dividerPattern = /^ {0,3}(?:-{3,}|\*{3,}|_{3,})[\t ]*$/;
  const fencePattern = /^[\t ]*(`{3,}|~{3,})[\t ]*([^\s`~]*)[^`~]*$/;
  const closingFencePattern = /^[\t ]*(`{3,}|~{3,})[\t ]*$/;
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
    const fence = line.match(fencePattern);
    if(fence){
      const [, marker, hint] = fence;
      const start = ++index;
      while(index < lines.length){
        const closing = lines[index].match(closingFencePattern)?.[1];
        if(closing?.startsWith(marker)) break;
        index++;
      }
      content.append(renderJoiaCodeBox(lines.slice(start, index).join("\n"), hint, highlight));
      if(index < lines.length) index++;
      continue;
    }
    if(dividerPattern.test(line)){
      content.append(document.createElement("hr"));
      index++;
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
      && !listPattern.test(lines[index]) && !dividerPattern.test(lines[index])
      && !fencePattern.test(lines[index]) && !tableStart(index)){
      paragraph.append(document.createElement("br"));
      appendJoiaInline(paragraph, lines[index++]);
    }
    content.append(paragraph);
  }
  return content;
}
