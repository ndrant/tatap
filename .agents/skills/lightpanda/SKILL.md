---
name: lightpanda
description: Headless browser automation, DOM inspection, user interaction simulation, console error auditing, and end-to-end web testing via Lightpanda MCP.
---

# Lightpanda Headless Browser Automation Skill

This skill guides the use of Lightpanda, an ultra-fast headless browser, to test, inspect, and automate web application workflows.

## Core Capabilities & Tool Mapping

Lightpanda is accessed via the `lightpanda` MCP server:

1. **Navigation & State**:
   - `goto(url, waitUntil)`: Navigate the current page. Prefers `load` (default) or `domcontentloaded`.
   - `waitForSelector(selector, timeout)`: Wait until a CSS selector appears in the DOM.
   - `waitForState(state)`: Wait for state (`networkidle`, `networkalmostidle`, etc.).
   - `getUrl()`: Return current page URL.

2. **Reading & Semantic Inspection**:
   - `tree(maxDepth, backendNodeId)`: Semantic tree of the page (roles, names, values, backendNodeIds). Default starting point.
   - `interactiveElements(url)`: Enumerate all interactive elements, controls, links, buttons, and listeners.
   - `nodeDetails(backendNodeId)`: Resolve unique CSS selector, tag, class, and attributes for a node.
   - `findElement(role, name)`: Locate candidates by role and text/regex.
   - `markdown(selector | backendNodeId, maxBytes)`: Readable text representation of a subtree or page.
   - `html(selector | backendNodeId)`: Raw HTML fixture capture.

3. **Interaction Simulation**:
   - `click(selector)`: Simulate click on an element (CSS selector required).
   - `fill(selector, value)`: Type text into inputs or textareas.
   - `selectOption(selector, value)`: Choose an option in `<select>` elements.
   - `setChecked(selector, checked)`: Check or uncheck checkboxes/radios.
   - `press(key)`: Send keyboard keystrokes.
   - `hover(selector)`: Trigger hover state.
   - `scroll(direction, amount)`: Scroll the viewport.

4. **Form & Data Extraction**:
   - `detectForms()`: Inspect forms, fields, and action targets.
   - `extract(schema)`: Schema-driven extraction from page content.
   - `screenshot()`: Visual capture (for layout/spatial debugging).

5. **Runtime Diagnostics**:
   - `consoleLogs()`: Read buffered browser console logs (`log`, `warn`, `error`). Clears buffer on read.

## Operating Rules for Lightpanda

- **Inspect Before Interacting**: Run `tree` or `interactiveElements` to understand the live state before clicking or filling.
- **Never Guess Selectors**: Use `interactiveElements` or `nodeDetails(backendNodeId)` to get exact, valid CSS selectors.
- **Re-inspect After Actions**: Single-page apps and AJAX dynamically update the DOM. Re-inspect after clicks or form submissions.
- **Always Audit Console Logs**: Call `consoleLogs` after navigation and interactions to catch hidden JavaScript exceptions, failed requests, or security policy warnings.
- **Clean Browser Sessions**: Reset or isolate testing workflows to avoid state bleeding.
