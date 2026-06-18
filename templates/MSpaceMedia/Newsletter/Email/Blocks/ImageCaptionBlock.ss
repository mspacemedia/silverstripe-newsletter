<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle" align="$safeAlignment">
        <% if $LinkURL %><a href="$LinkURL.ATT" target="_blank"><% end_if %>
        <% with $ScaledImage %>
            <img src="$AbsoluteURL" alt="$Up.Alt.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;<% if $Up.Alignment == center %>margin:0 auto;<% end_if %>" />
        <% end_with %>
        <% if $LinkURL %></a><% end_if %>
        <% if $Caption %><div style="font-family:$EffectiveFont;font-size:13px;color:$Brand.FooterTextColor;margin-top:6px;">$Caption.XML</div><% end_if %>
    </td>
</tr>
