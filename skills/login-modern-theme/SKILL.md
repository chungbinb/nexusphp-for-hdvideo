# Login Modern Theme Skill

## Purpose
Apply a reusable, modern login-page visual style for ASP.NET Web Forms projects while keeping existing authentication logic unchanged.

## Design Direction
- Visual tone: professional, bright, and trustworthy.
- Layout: two-column card on desktop, single-column on mobile.
- Left side: brand/hero panel.
- Right side: login form panel.
- Motion: subtle hover and focus transitions only.

## Core Style Tokens
```css
:root {
  --bg-top: #eaf4ff;
  --bg-mid: #f5fbff;
  --bg-bottom: #ffffff;
  --brand-a: #0d5f99;
  --brand-b: #1482b8;
  --brand-c: #1bbadf;
  --text-main: #1f2d3d;
  --text-muted: #637084;
  --field-border: #cdd9ea;
  --field-focus: #1b9bc8;
  --error-bg: #fdecef;
  --error-border: #f4c4cb;
}
```

## Structure Template
Use this structure in `login.aspx` body:
```html
<form id="form1" runat="server">
  <div class="login-shell">
    <div class="login-hero">
      <div class="hero-title"><asp:Literal ID="litHeroTitle" runat="server"></asp:Literal></div>
      <p class="hero-text"><asp:Literal ID="litHeroText" runat="server"></asp:Literal></p>
      <span class="hero-chip"><asp:Literal ID="litHeroChip" runat="server"></asp:Literal></span>
    </div>

    <div class="login-container">
      <h1 class="login-title"><asp:Literal ID="litTitle" runat="server"></asp:Literal></h1>
      <p class="login-subtitle"><asp:Literal ID="litLoginSubtitle" runat="server"></asp:Literal></p>

      <div class="form-group">
        <label><asp:Literal ID="litUsername" runat="server"></asp:Literal></label>
        <asp:TextBox ID="txtUsername" runat="server" CssClass="input-control"></asp:TextBox>
      </div>

      <div class="form-group">
        <label><asp:Literal ID="litPassword" runat="server"></asp:Literal></label>
        <asp:TextBox ID="txtPassword" runat="server" TextMode="Password" CssClass="input-control"></asp:TextBox>
      </div>

      <div class="form-group">
        <label><asp:Literal ID="litLanguage" runat="server"></asp:Literal></label>
        <asp:DropDownList ID="ddlLanguage" runat="server" CssClass="input-control"></asp:DropDownList>
      </div>

      <asp:Button ID="btnLogin" runat="server" CssClass="btn-login" OnClick="btnLogin_Click" />
      <asp:Label ID="lblError" runat="server" CssClass="error-message"></asp:Label>
    </div>
  </div>
</form>
```

## Required Localization Keys
Add these keys for each language in `LanguageHelper`:
- `LoginHeroTitle`
- `LoginHeroText`
- `LoginHeroChip`
- `LoginSubtitle`

## Code-behind Binding
In `login.aspx.cs` `UpdateLanguage()`:
```csharp
litHeroTitle.Text = LanguageHelper.GetText("LoginHeroTitle", lang);
litHeroText.Text = LanguageHelper.GetText("LoginHeroText", lang);
litHeroChip.Text = LanguageHelper.GetText("LoginHeroChip", lang);
litLoginSubtitle.Text = LanguageHelper.GetText("LoginSubtitle", lang);
```

## Non-goals
- Do not change validation/authentication logic.
- Do not rename existing login control IDs unless project requires it.

## Adoption Checklist
1. Copy the CSS block and markup structure.
2. Keep existing server-side event handlers.
3. Add localization keys and bindings.
4. Verify desktop and mobile rendering.
5. Verify error state and language switch state.
