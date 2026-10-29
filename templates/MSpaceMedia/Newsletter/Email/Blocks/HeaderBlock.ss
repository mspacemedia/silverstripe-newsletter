<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td class="$BlockClass" style="$InlineStyle" align="$safeAlignment">
        <% if $LinkURL %><a href="$LinkURL.ATT" target="_blank" style="text-decoration:none;color:$EffectiveHeadingColor;"><% end_if %>
        <% if $EffectiveLogo %>
            <% with $EffectiveLogo %>
                <img src="$AbsoluteURL" alt="$Up.Alt.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;<% if $Up.Alignment == center %>margin:0 auto;<% end_if %>" />
            <% end_with %>
        <% else_if $WordmarkText %>
            <span style="{$HeadingStyle(3)}letter-spacing:0.02em;">$WordmarkText.XML</span>
        <% end_if %>
        <% if $LinkURL %></a><% end_if %>
    </td>
</tr>
